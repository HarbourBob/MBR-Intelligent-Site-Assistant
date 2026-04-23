<?php
/**
 * REST API — public endpoints for the chat widget.
 *
 * Endpoints:
 *   POST /wp-json/mbr-isa/v1/ask       { query, session_id? }  -> chat response
 *   POST /wp-json/mbr-isa/v1/feedback  { query_id, feedback }  -> { ok: true }
 *
 * Both endpoints are public (no auth), but nonce-protected via the widget
 * loader, and rate-limited per IP hash.
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
                    'query' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'session_id' => [
                        'required' => false,
                        'type'     => 'string',
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
                "SELECT id, created_at FROM {$table} WHERE id = %d LIMIT 1",
                $query_id
            )
        );

        if ( ! $row ) {
            return new WP_Error( 'mbr_isa_not_found', 'Query not found', [ 'status' => 404 ] );
        }

        $created_at = strtotime( (string) $row->created_at );
        if ( $created_at && ( time() - $created_at ) > HOUR_IN_SECONDS ) {
            return new WP_Error(
                'mbr_isa_feedback_expired',
                'Feedback window has closed for this query',
                [ 'status' => 410 ]
            );
        }

        // 4. Update.
        $updated = $wpdb->update(
            $table,
            [ 'feedback' => $feedback ],
            [ 'id' => $query_id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'mbr_isa_db_error', 'Could not record feedback', [ 'status' => 500 ] );
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }
}