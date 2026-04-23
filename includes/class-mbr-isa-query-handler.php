<?php
/**
 * Query Handler — end-to-end orchestration of a user query.
 *
 * Pipeline:
 *   1. Sanitise the query (length limits, basic cleaning).
 *   2. Try intent match — if hit, return canned response and stop.
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

        // 1. Intent match first.
        $intent = $this->intents->match( $raw_query );
        if ( $intent ) {
            $response = $this->responder->format_intent_response( $intent );
            $response['session_id']   = $this->ensure_session_id( $session_id );
            $response['query_echo']   = $raw_query;
            $query_id = $this->log_query( $raw_query, [], $response, $session_id, $intent['id'] );
            if ( null !== $query_id ) {
                $response['query_id'] = $query_id;
            }
            return $response;
        }

        // 2. Tokenise + expand.
        $tokens_raw      = $this->tokeniser->tokenise( $raw_query );
        $tokens_unique   = array_values( array_unique( $tokens_raw ) );
        $tokens_expanded = $this->synonyms->expand( $tokens_unique );

        if ( empty( $tokens_expanded ) ) {
            $response = $this->responder->format_empty_query_response();
            $response['session_id'] = $this->ensure_session_id( $session_id );
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

        $search_results = $this->indexer->search( $raw_query, 10 );

        // If synonyms added new tokens that the raw query didn't already cover,
        // do a supplemental search and merge scores.
        $extra_tokens = array_diff( $tokens_expanded, $tokens_unique );
        if ( ! empty( $extra_tokens ) ) {
            $search_results = $this->merge_synonym_results( $search_results, $extra_tokens );
        }

        // 4. Format.
        $response = $this->responder->format_search_response( $search_results, $tokens_expanded );
        $response['session_id'] = $this->ensure_session_id( $session_id );
        $response['query_echo'] = $raw_query;

        // 5. Log.
        $query_id = $this->log_query( $raw_query, $tokens_expanded, $response, $session_id );
        if ( null !== $query_id ) {
            $response['query_id'] = $query_id;
        }

        return $response;
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
        if ( function_exists( 'mb_substr' ) ) {
            $text = mb_substr( $text, 0, self::MAX_QUERY_LENGTH );
        } else {
            $text = substr( $text, 0, self::MAX_QUERY_LENGTH );
        }
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
     * @param string|null $session_id
     * @return string
     */
    private function ensure_session_id( $session_id ) {
        $session_id = is_string( $session_id ) ? trim( $session_id ) : '';
        if ( '' === $session_id || strlen( $session_id ) > 32 ) {
            $session_id = substr( md5( uniqid( 'mbr_isa_', true ) ), 0, 32 );
        }
        return $session_id;
    }

    /**
     * Write a row to the query log.
     *
     * @param string      $raw_query
     * @param string[]    $normalised_tokens
     * @param array       $response
     * @param string|null $session_id
     * @param string|null $intent_id
     * @return int|null Inserted row ID, or null if logging was skipped or failed.
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

        $ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip_hash  = '' !== $ip ? hash( 'sha256', $ip . wp_salt() ) : null;

        $session_id_clean = is_string( $session_id ) ? substr( $session_id, 0, 32 ) : null;

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
                'created_at'        => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%f', '%d', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }
}