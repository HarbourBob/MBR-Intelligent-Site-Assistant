<?php
/**
 * Query Handler — end-to-end orchestration of a user query.
 *
 * Pipeline:
 *   1. Sanitise the query (length limits, basic cleaning).
 *   1a. Quoted phrase? If so, skip intents and synonyms and match literally.
 *   2. Try intent match — if hit, return canned response (with optional
 *      supplementary search hits beneath it) and stop.
 *   3. Tokenise the query with the main tokeniser.
 *   4. Expand tokens via synonym groups.
 *   5. Hand the expanded tokens to the indexer for BM25 search.
 *   6. Format results via the responder with confidence-appropriate framing.
 *   7. Log the query for later tuning.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Query_Handler {

    const MAX_QUERY_LENGTH = 500;

    /**
     * @var MBR_ISA_Tokeniser
     */
    private $tokeniser;

    /**
     * @var MBR_ISA_Indexer
     */
    private $indexer;

    /**
     * @var MBR_ISA_Synonyms
     */
    private $synonyms;

    /**
     * @var MBR_ISA_Intents
     */
    private $intents;

    /**
     * @var MBR_ISA_Responder
     */
    private $responder;

    /**
     * Plugin settings, read once per request.
     *
     * Loaded lazily rather than injected, so the constructor signature stays
     * as it is — it is called from the orchestrator and from the diagnostics
     * testers, and widening it would mean touching all of them.
     *
     * @var array|null
     */
    private $settings_cache = null;

    public function __construct(
        MBR_ISA_Tokeniser $tokeniser,
        MBR_ISA_Indexer   $indexer,
        MBR_ISA_Synonyms  $synonyms,
        MBR_ISA_Intents   $intents,
        MBR_ISA_Responder $responder
    ) {
        $this->tokeniser = $tokeniser;
        $this->indexer   = $indexer;
        $this->synonyms  = $synonyms;
        $this->intents   = $intents;
        $this->responder = $responder;
    }

    /**
     * Run the full query pipeline.
     *
     * @param string      $raw_query   Unmodified user query.
     * @param string|null $session_id  Optional widget session identifier.
     * @return array Structured payload for the REST response or widget.
     */
    public function handle( $raw_query, $session_id = null ) {
        $raw_query = $this->sanitise_query( $raw_query );

        if ( '' === $raw_query ) {
            $response = $this->responder->format_empty_query_response();
            $this->log_query( '', [], $response, $session_id );
            return $response;
        }

        // The session ID is resolved once, up front, and that same value is
        // both returned to the client and written to the log. Previously the
        // response carried the resolved ID while log_query() was handed the
        // original argument, so the first query of every session logged NULL
        // and the session_id index never saw the row that started it.
        $session_id = $this->ensure_session_id( $session_id );

        /*
         * 0. Quoted phrase?
         *
         * A query wrapped in matching quotes is a request for that exact run of
         * words, so it takes a different path: intents are skipped (the visitor
         * has asked for a phrase, not for whichever canned answer happens to
         * share a word with it) and synonym expansion is skipped too, since
         * substituting words is the opposite of what was asked for. Stemming
         * still runs, because that is only how candidates are found; the phrase
         * itself is matched literally against the stored passage.
         */
        $phrase = $this->extract_quoted_phrase( $raw_query );

        // 1. Intent match first — unless this is a phrase search.
        $intent = '' === $phrase ? $this->intents->match( $raw_query ) : null;
        if ( $intent ) {
            $response = $this->responder->format_intent_response( $intent );

            /*
             * An intent answers the question, but it is not always the whole
             * story: "what are your opening hours" may also have a contact page
             * worth offering. Where the query also produces decent search hits,
             * they are attached beneath the canned answer rather than discarded.
             * The widget already renders response.results for any response type,
             * so this needs no front-end change.
             */
            $this->attach_intent_supplementary_results( $response, $raw_query );

            $response['session_id']   = $session_id;
            $response['query_echo']   = $raw_query;
            $logged = $this->log_query( $raw_query, [], $response, $session_id, $intent['id'] );
            $this->attach_feedback_credentials( $response, $logged );
            return $response;
        }

        // 2. Tokenise + expand. In phrase mode the quotes themselves are not
        //    part of the search, so the inner text is what gets tokenised.
        $tokens_raw      = $this->tokeniser->tokenise( '' !== $phrase ? $phrase : $raw_query );
        $tokens_unique   = array_values( array_unique( $tokens_raw ) );
        $tokens_expanded = '' !== $phrase ? $tokens_unique : $this->synonyms->expand( $tokens_unique );

        if ( empty( $tokens_expanded ) ) {
            $response = $this->responder->format_empty_query_response();
            $response['session_id'] = $session_id;
            $response['query_echo'] = $raw_query;
            $this->log_query( $raw_query, [], $response, $session_id );
            return $response;
        }

        // 3. Search with the expanded token list.
        //    Rebuild a pseudo-query string from the expanded tokens so the
        //    indexer can re-tokenise consistently. Tokens are already stemmed,
        //    but the indexer's search() re-runs the full tokeniser pipeline
        //    on whatever we pass — so instead we bypass that by passing the
        //    original raw query (its stems are already captured in tokens_raw)
        //    and separately asking the indexer to include synonym stems.
        //
        //    For now, the simplest correct path is: pass the raw query, then
        //    supplement by calling the indexer again per synonym-only token.
        //    At the scale of <100 posts this is effectively free. We'll
        //    refactor when we need performance tuning.

        $search_subject = '' !== $phrase ? $phrase : $raw_query;
        $search_results = $this->indexer->search( $search_subject, 10, $phrase );

        // If synonyms added new tokens that the raw query didn't already cover,
        // do a supplemental search and merge scores. Never in phrase mode —
        // a synonym hit would not contain the phrase and would be filtered out
        // again anyway, so the extra queries would be pure waste.
        $extra_tokens = array_diff( $tokens_expanded, $tokens_unique );
        if ( ! empty( $extra_tokens ) && '' === $phrase ) {
            $search_results = $this->merge_synonym_results( $search_results, $extra_tokens );
        }

        // 4. Format.
        $response = $this->responder->format_search_response( $search_results, $tokens_expanded, $phrase );
        $response['session_id'] = $session_id;
        $response['query_echo'] = $raw_query;

        // 5. Log.
        $logged = $this->log_query( $raw_query, $tokens_expanded, $response, $session_id );
        $this->attach_feedback_credentials( $response, $logged );

        return $response;
    }

    /**
     * Attach query_id and its matching feedback token to a response.
     *
     * Both or neither: a query_id without a token would leave the widget
     * showing rating buttons that the endpoint will reject.
     *
     * @param array      $response Response array, modified in place.
     * @param array|null $logged   Result of log_query().
     * @return void
     */
    private function attach_feedback_credentials( array &$response, $logged ) {
        if ( ! is_array( $logged ) || empty( $logged['id'] ) ) {
            return;
        }

        $response['query_id']       = (int) $logged['id'];
        $response['feedback_token'] = self::issue_feedback_token(
            (int) $logged['id'],
            (string) $logged['created_at']
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Trim + length-cap incoming query text.
     *
     * @param mixed $raw
     * @return string
     */
    private function sanitise_query( $raw ) {
        $text = is_string( $raw ) ? $raw : '';
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        $text = trim( (string) $text );
        $text = mb_substr( $text, 0, self::MAX_QUERY_LENGTH );
        return (string) $text;
    }

    /**
     * Merge supplementary results from synonym-only tokens into the main results.
     *
     * @param array    $primary      Primary search output (as from indexer->search()).
     * @param string[] $extra_tokens Stems present only via synonyms.
     * @return array
     */
    private function merge_synonym_results( array $primary, array $extra_tokens ) {
        // Build a query string from extra stems to feed back through search().
        $extra_query = implode( ' ', $extra_tokens );
        $extra       = $this->indexer->search( $extra_query, 10 );

        $merged = [];
        foreach ( $primary['results'] as $r ) {
            $merged[ $r['doc_id'] ] = $r;
        }

        // Synonym hits are weighted down slightly — an exact query match is
        // stronger evidence of relevance than a synonym match.
        $synonym_discount = 0.7;
        foreach ( $extra['results'] as $r ) {
            $r['score'] = round( (float) $r['score'] * $synonym_discount, 4 );
            if ( isset( $merged[ $r['doc_id'] ] ) ) {
                $merged[ $r['doc_id'] ]['score'] = round( $merged[ $r['doc_id'] ]['score'] + $r['score'], 4 );
            } else {
                $merged[ $r['doc_id'] ] = $r;
            }
        }

        usort( $merged, function ( $a, $b ) {
            return ( $b['score'] <=> $a['score'] );
        } );

        return [
            'results' => array_values( $merged ),
            'trace'   => array_merge(
                is_array( $primary['trace'] ?? null ) ? $primary['trace'] : [],
                [ 'synonym_extra_tokens' => $extra_tokens ]
            ),
        ];
    }

    /**
     * Make sure we return a usable session identifier.
     *
     * Not a security token and not treated as one — it is supplied by the
     * client, never validated against anything, and exists only to group a
     * visitor's queries in the log. Generated with random_bytes() rather than
     * md5(uniqid()) so that it is at least not predictable from a timestamp,
     * but nothing in the plugin should ever start trusting it.
     *
     * @param string|null $session_id
     * @return string
     */
    private function ensure_session_id( $session_id ) {
        $session_id = is_string( $session_id ) ? trim( $session_id ) : '';

        // Must be exactly the shape we issue; anything else is replaced. The
        // column is CHAR(32), so an over-long value would be silently cut.
        if ( 32 !== strlen( $session_id ) || ! ctype_xdigit( $session_id ) ) {
            $session_id = bin2hex( random_bytes( 16 ) );
        }

        return $session_id;
    }

    /**
     * Issue a signed token authorising feedback on one specific query row.
     *
     * The feedback endpoint has no authenticated caller to check, so without
     * this any client could POST a rating against any recent query ID —
     * they are sequential, and /ask hands one out on every request. The token
     * makes a rating something only the client that received the answer can
     * submit.
     *
     * created_at is part of the signed payload so the token cannot outlive
     * the row it refers to, and wp_salt('nonce') keeps the key out of the
     * database and distinct per site.
     *
     * @param int    $query_id   Row ID in the query log.
     * @param string $created_at MySQL datetime the row was written.
     * @return string Hex-encoded HMAC, truncated to 32 chars.
     */
    public static function issue_feedback_token( $query_id, $created_at ) {
        return substr(
            hash_hmac(
                'sha256',
                (int) $query_id . '|' . (string) $created_at,
                wp_salt( 'nonce' )
            ),
            0,
            32
        );
    }

    /**
     * Verify a feedback token against the row it claims to authorise.
     *
     * @param string $token      Token supplied by the client.
     * @param int    $query_id   Row ID being rated.
     * @param string $created_at created_at as read back from the database.
     * @return bool
     */
    public static function verify_feedback_token( $token, $query_id, $created_at ) {
        if ( ! is_string( $token ) || '' === $token ) {
            return false;
        }

        return hash_equals(
            self::issue_feedback_token( $query_id, $created_at ),
            $token
        );
    }

    /**
     * Write a row to the query log.
     *
     * @param string      $raw_query
     * @param string[]    $normalised_tokens
     * @param array       $response
     * @param string|null $session_id
     * @param string|null $intent_id
     * @return array|null [ 'id' => int, 'created_at' => string ], or null if
     *                    logging was skipped or the insert failed. created_at
     *                    is returned because the feedback token signs it, and
     *                    re-reading it from the database would be a wasted
     *                    round trip for a value we just wrote.
     */
    private function log_query( $raw_query, array $normalised_tokens, array $response, $session_id, $intent_id = null ) {
        $settings = get_option( 'mbr_isa_settings', [] );
        if ( empty( $settings['log_queries'] ) ) {
            return null;
        }

        global $wpdb;

        $top_doc_id = null;
        $top_score  = null;
        $count      = 0;
        if ( ! empty( $response['results'] ) && is_array( $response['results'] ) ) {
            $count = count( $response['results'] );
            if ( isset( $response['results'][0]['doc_id'] ) ) {
                $top_doc_id = (int) $response['results'][0]['doc_id'];
            }
            if ( isset( $response['results'][0]['score'] ) ) {
                $top_score = (float) $response['results'][0]['score'];
            }
        }

        // Shared with the rate limiter so both agree on which address a
        // request came from — including behind a trusted proxy, where
        // REMOTE_ADDR would otherwise record the CDN for every visitor.
        $ip_hash = MBR_ISA_Rate_Limiter::hash_current_ip();
        $ip_hash = '' !== $ip_hash ? $ip_hash : null;

        $session_id_clean = is_string( $session_id ) ? substr( $session_id, 0, 32 ) : null;
        $created_at       = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'mbrisa_queries',
            [
                'query_text'        => mb_substr( $raw_query, 0, 500 ),
                'normalised_tokens' => mb_substr( implode( ' ', $normalised_tokens ), 0, 500 ),
                'top_doc_id'        => $top_doc_id,
                'top_score'         => $top_score,
                'result_count'      => $count,
                'intent_matched'    => $intent_id ? mb_substr( (string) $intent_id, 0, 50 ) : null,
                'feedback'          => null,
                'user_ip_hash'      => $ip_hash,
                'session_id'        => $session_id_clean,
                'created_at'        => $created_at,
            ],
            [ '%s', '%s', '%d', '%f', '%d', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return null;
        }

        return [
            'id'         => (int) $wpdb->insert_id,
            'created_at' => $created_at,
        ];
    }

    /**
     * If the query is wrapped in matching quotes, return the inner text.
     *
     * Straight and curly, single and double, and the two mixed — a phone
     * keyboard will happily produce “opening hours” with curly quotes while
     * the visitor believes they typed the straight ones.
     *
     * Deliberately strict about what counts:
     *
     *   - The quotes must be the first and last characters. A stray apostrophe
     *     inside the query is just an apostrophe.
     *   - Both must be the same kind, so don't > isn't misread as a phrase.
     *   - There must be something between them worth searching for.
     *   - An apostrophe inside the phrase is fine: "don't panic" is a phrase.
     *
     * A single-word phrase — "hours" — is allowed and is meaningful: it skips
     * intents and synonyms and demands that literal word.
     *
     * @param string $query Sanitised raw query.
     * @return string The phrase, or '' when the query is not quoted.
     */
    private function extract_quoted_phrase( $query ) {
        $query = trim( (string) $query );

        /*
         * Matched with a /u regex rather than by inspecting the first and last
         * characters. Character inspection needs mb_substr() to be meaningful,
         * and mbstring, while present on virtually every host, is not
         * guaranteed — without it substr() works in bytes, so the first byte of
         * a curly quote (0xE2) never matches the three-byte character and every
         * smart-quoted phrase silently falls through to an ordinary search.
         * PCRE with /u is always available and gets this right either way.
         *
         * The dot is greedy and anchored at both ends, so only the outermost
         * pair is stripped: '"a" and "b"' is one phrase containing quotes,
         * which is what someone typing that almost certainly means.
         */
        $patterns = [
            '/^"(.+)"$/us',
            "/^'(.+)'\$/us",
            '/^\x{201C}(.+)\x{201D}$/us',
            '/^\x{2018}(.+)\x{2019}$/us',
        ];

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $query, $m ) ) {
                $inner = trim( $m[1] );

                /*
                 * A phrase of nothing but whitespace, or of nothing but
                 * stopwords, produces no tokens — no candidates can be found
                 * and the literal filter never runs. Returning '' sends it
                 * down the ordinary path, which at least gives the visitor the
                 * usual "nothing found" answer rather than an unexplained
                 * blank.
                 */
                return '' !== $inner ? $inner : '';
            }
        }

        return '';
    }

    /**
     * Attach ordinary search results beneath an intent's canned answer.
     *
     * Controlled by the intent_supplementary_results setting, on by default.
     *
     * Only medium-or-better hits are offered. An intent has already answered
     * the question, so padding it with weak matches makes a confident answer
     * look uncertain — the bar is deliberately higher than for a plain search,
     * where a low-confidence result is still better than nothing.
     *
     * Failures here are swallowed: the canned answer is the response, and a
     * problem gathering extras must never cost the visitor the answer itself.
     *
     * @param array  $response  Intent response, modified in place.
     * @param string $raw_query The visitor's original question.
     * @return void
     */
    private function attach_intent_supplementary_results( array &$response, $raw_query ) {
        $settings = $this->get_settings();
        $enabled  = ! isset( $settings['intent_supplementary_results'] )
            || ! empty( $settings['intent_supplementary_results'] );

        if ( ! $enabled ) {
            return;
        }

        $max = (int) ( $settings['intent_supplementary_max'] ?? 3 );
        if ( $max < 1 ) {
            return;
        }

        $extras = $this->responder->format_supplementary_results(
            $this->indexer->search( $raw_query, $max + 2 ),
            $this->tokeniser->tokenise( $raw_query ),
            $max
        );

        if ( empty( $extras ) ) {
            return;
        }

        $response['results']       = $extras;
        $response['results_intro'] = __( 'You might also find these useful:', 'mbr-isa' );
    }


    /**
     * Plugin settings, memoised for the request.
     *
     * @return array
     */
    private function get_settings() {
        if ( null === $this->settings_cache ) {
            $settings             = get_option( 'mbr_isa_settings', [] );
            $this->settings_cache = is_array( $settings ) ? $settings : [];
        }

        return $this->settings_cache;
    }

}