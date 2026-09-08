<?php
/**
 * REST API — public endpoints for the chat widget.
 *
 * Endpoints:
 *   POST /wp-json/mbr-isa/v1/ask       { query, session_id? }        -> chat response
 *   POST /wp-json/mbr-isa/v1/feedback  { query_id, token, feedback } -> { ok: true }
 *
 * Both endpoints are public and unauthenticated by design: a site-search
 * assistant has to answer visitors who are not logged in.
 *
 * The widget sends an X-WP-Nonce header, and the REST infrastructure uses it
 * to establish the current user for a logged-in visitor — but it is NOT an
 * authentication boundary here and is deliberately not verified. Two reasons:
 *
 *   1. There is nothing to authenticate. Both endpoints are open by design,
 *      so a nonce check would exclude nobody an attacker cannot trivially
 *      satisfy by loading a page first.
 *   2. Enforcing it would break the widget on cached sites. The nonce is
 *      printed into the page by wp_localize_script(), so a page cache serves
 *      one visitor's nonce to everybody — and a stale or foreign nonce would
 *      fail verification on a request that has no business failing.
 *
 * What actually protects these endpoints:
 *   - Per-IP-hash rate limiting, in separate buckets per endpoint.
 *   - /feedback additionally requires a signed token issued by /ask, which
 *     binds a feedback submission to the query that produced it.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_REST {

    const NAMESPACE_V1 = 'mbr-isa/v1';

    /**
     * @var MBR_ISA_Query_Handler
     */
    private $query_handler;

    /**
     * @var MBR_ISA_Rate_Limiter
     */
    private $rate_limiter;

    public function __construct( MBR_ISA_Query_Handler $query_handler, MBR_ISA_Rate_Limiter $rate_limiter ) {
        $this->query_handler = $query_handler;
        $this->rate_limiter  = $rate_limiter;
    }

    /**
     * Register the routes on rest_api_init.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            self::NAMESPACE_V1,
            '/ask',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_ask' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    /*
                     * Bounded at the door.
                     *
                     * sanitise_query() caps the text at MAX_QUERY_LENGTH, but
                     * it does so last — after wp_strip_all_tags() and a
                     * unicode whitespace regex have each run over the whole
                     * body. An anonymous caller could therefore post several
                     * megabytes and have every byte processed before the cap
                     * discarded all but 500 characters of it. Declaring the
                     * bound here means WordPress rejects it with a 400 before
                     * the callback is ever reached.
                     *
                     * Four times the working cap, so multibyte input is never
                     * refused for being long in bytes while being short in
                     * characters. Anything past that is not a question.
                     */
                    'query' => [
                        'required'          => true,
                        'type'              => 'string',
                        'maxLength'         => MBR_ISA_Query_Handler::MAX_QUERY_LENGTH * 4,
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    /*
                     * Bounded but not pattern-matched, which is a deliberate
                     * departure from the strict 32-hex rule.
                     *
                     * ensure_session_id() already replaces anything that is
                     * not exactly the shape we issue, so a malformed value
                     * costs nothing and recovers silently. Rejecting it at the
                     * route instead turns that recovery into a 400 for the
                     * whole search — the visitor gets an error instead of an
                     * answer because of a stale value in their own browser
                     * storage, which is a poor trade for a field that is not a
                     * security control. The length bound is what actually
                     * matters here, and it is kept.
                     */
                    'session_id' => [
                        'required'          => false,
                        'type'              => 'string',
                        'maxLength'         => 64,
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE_V1,
            '/feedback',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_feedback' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'query_id' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                    'token' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'feedback' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                ],
            ]
        );
    }

    /**
     * POST /ask handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_ask( WP_REST_Request $request ) {
        // Rate limit.
        $settings = get_option( 'mbr_isa_settings', [] );
        $limit    = isset( $settings['rate_limit_per_min'] ) ? (int) $settings['rate_limit_per_min'] : 30;
        $ip_hash  = MBR_ISA_Rate_Limiter::hash_current_ip();

        if ( ! $this->rate_limiter->check( $ip_hash, max( 1, $limit ), 60 ) ) {
            return new WP_Error(
                'mbr_isa_rate_limited',
                __( 'Too many requests. Please wait a moment and try again.', 'mbr-isa' ),
                [ 'status' => 429 ]
            );
        }

        $query      = (string) $request->get_param( 'query' );
        $session_id = $request->get_param( 'session_id' );
        $session_id = is_string( $session_id ) ? $session_id : null;

        $response = $this->query_handler->handle( $query, $session_id );

        return new WP_REST_Response( $response, 200 );
    }

    /**
     * POST /feedback handler.
     *
     * Records a thumbs up/down against a previously-logged query. Guarded by:
     *   - Rate limiting (per IP, separate bucket from /ask)
     *   - A signed token issued by /ask, tying the rating to the query that
     *     produced it (see MBR_ISA_Query_Handler::issue_feedback_token())
     *   - A time window (only queries created within the last hour accept feedback)
     *   - An existence check (404 if the query ID doesn't exist)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_feedback( WP_REST_Request $request ) {
        global $wpdb;

        // 1. Rate limit — stricter than /ask since legitimate feedback
        //    is infrequent. Key is a separate bucket so abuse of one
        //    endpoint doesn't block the other.
        $ip_hash = MBR_ISA_Rate_Limiter::hash_current_ip();
        if ( ! $this->rate_limiter->check( 'fb_' . $ip_hash, 20, 60 ) ) {
            return new WP_Error(
                'mbr_isa_rate_limited',
                __( 'Too many requests. Please wait a moment.', 'mbr-isa' ),
                [ 'status' => 429 ]
            );
        }

        // 2. Input validation.
        $query_id = (int) $request->get_param( 'query_id' );
        $feedback = (int) $request->get_param( 'feedback' );

        if ( $query_id <= 0 ) {
            return new WP_Error( 'mbr_isa_invalid_query_id', 'Invalid query_id', [ 'status' => 400 ] );
        }
        if ( ! in_array( $feedback, [ -1, 0, 1 ], true ) ) {
            return new WP_Error( 'mbr_isa_invalid_feedback', 'feedback must be -1, 0, or 1', [ 'status' => 400 ] );
        }

        // 3. Existence check + time window. Only accept feedback on queries
        //    created within the last hour; anything older is either stale
        //    or a scripted scraper trying to bulk-set feedback.
        $table = $wpdb->prefix . 'mbrisa_queries';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, created_at, feedback FROM {$table} WHERE id = %d LIMIT 1",
                $query_id
            )
        );

        if ( ! $row ) {
            return new WP_Error( 'mbr_isa_not_found', 'Query not found', [ 'status' => 404 ] );
        }

        // 3a. Token check. Query IDs are sequential and /ask returns one on
        //     every request, so existence alone authorises nothing — without
        //     this, anyone could walk the ID space and rate other visitors'
        //     answers. The token proves the caller received this answer.
        $token = $request->get_param( 'token' );
        if ( ! MBR_ISA_Query_Handler::verify_feedback_token(
            is_string( $token ) ? $token : '',
            $query_id,
            (string) $row->created_at
        ) ) {
            return new WP_Error(
                'mbr_isa_invalid_token',
                'Invalid or missing feedback token',
                [ 'status' => 403 ]
            );
        }

        // 3b. One rating per query. The token is valid for an hour and is not
        //     consumed by use, so without this a caller holding one could
        //     resubmit against the same query for the rest of that window and
        //     move the statistics on their own. The widget already replaces
        //     the rating strip with a confirmation on the first click, so
        //     this closes the gap between what the interface offers and what
        //     the endpoint accepts rather than removing anything a visitor
        //     could previously do.
        //
        //     The column is NULL until rated, which is what makes "already
        //     rated" distinguishable from the legitimate neutral rating of 0.
        //
        //     Deliberately after the token check. Whether a given query has
        //     been rated is information, and answering that for an unsigned
        //     request would hand an ID-walker exactly the read primitive the
        //     token exists to deny them.
        if ( null !== $row->feedback ) {
            return new WP_Error(
                'mbr_isa_feedback_recorded',
                'Feedback has already been recorded for this query',
                [ 'status' => 409 ]
            );
        }

        /*
         * created_at is written with current_time('mysql'), which is site-local
         * time. WordPress forces PHP's default timezone to UTC, so strtotime()
         * would otherwise read that local string as though it were UTC and the
         * window would be wrong by the site's offset in whichever direction it
         * runs — an hour early on a negative offset, which rejects every rating
         * the instant it is submitted, and an hour late on a positive one,
         * which quietly weakens the control this implements.
         */
        $created_at = strtotime( get_gmt_from_date( (string) $row->created_at ) );
        if ( $created_at && ( time() - $created_at ) > HOUR_IN_SECONDS ) {
            return new WP_Error(
                'mbr_isa_feedback_expired',
                'Feedback window has closed for this query',
                [ 'status' => 410 ]
            );
        }

        // 4. Update, conditionally.
        //
        //    The check above reads the column and this writes it, and nothing
        //    holds the row in between, so two submissions arriving together
        //    can both pass the check and the second overwrites the first —
        //    the "one rating per answer" rule enforced everywhere except at
        //    the moment it matters. The window is small and the prize is
        //    changing your own rating, so this is an integrity fault rather
        //    than a vulnerability, but the fix costs nothing: make the write
        //    itself the test by carrying the NULL into the WHERE clause, so
        //    the database decides which of the two arrived first.
        //
        //    The earlier check stays. It answers the ordinary case with the
        //    reasoning it documents — after the token check, so an ID-walker
        //    cannot learn whether a query has been rated — and this is only
        //    reached when two requests genuinely raced.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET feedback = %d WHERE id = %d AND feedback IS NULL",
                $feedback,
                $query_id
            )
        );

        if ( false === $updated ) {
            return new WP_Error( 'mbr_isa_db_error', 'Could not record feedback', [ 'status' => 500 ] );
        }

        //    Zero rows means the row was rated between the check and the
        //    write. NULL to -1, 0 or 1 is always a change, so a matched row
        //    always reports one; zero cannot mean "updated to the same
        //    value" here. The widget treats 409 as success, so a visitor
        //    whose first submission landed sees no error either way.
        if ( 0 === $updated ) {
            return new WP_Error(
                'mbr_isa_feedback_recorded',
                'Feedback has already been recorded for this query',
                [ 'status' => 409 ]
            );
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }
}