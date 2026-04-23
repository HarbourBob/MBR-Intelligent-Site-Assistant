<?php
/**
 * Indexer — populates the inverted index and runs searches against it.
 *
 * Responsibilities:
 *   - Index individual posts on save (save_post hook).
 *   - Remove posts from the index when deleted or trashed.
 *   - Full rebuild on demand (admin button).
 *   - Search: given a query string, return ranked documents with field-weighted BM25.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Indexer {

    /**
     * @var MBR_ISA_Tokeniser
     */
    private $tokeniser;

    /**
     * @var MBR_ISA_BM25
     */
    private $bm25;

    /**
     * Plugin settings array (cached).
     *
     * @var array
     */
    private $settings;

    public function __construct( MBR_ISA_Tokeniser $tokeniser, MBR_ISA_BM25 $bm25 ) {
        $this->tokeniser = $tokeniser;
        $this->bm25      = $bm25;
        $this->settings  = get_option( 'mbr_isa_settings', [] );
    }

    public function register_hooks() {
        add_action( 'save_post',    [ $this, 'on_save_post' ], 10, 3 );
        add_action( 'deleted_post', [ $this, 'on_delete_post' ] );
        add_action( 'trashed_post', [ $this, 'on_delete_post' ] );
    }

    // =========================================================================
    // Hook handlers.
    // =========================================================================

    public function on_save_post( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! $post instanceof WP_Post ) {
            return;
        }
        if ( 'publish' !== $post->post_status ) {
            $this->remove_post( $post_id );
            return;
        }
        if ( ! $this->is_indexable_post_type( $post->post_type ) ) {
            return;
        }
        $this->index_post( $post );
    }

    public function on_delete_post( $post_id ) {
        $this->remove_post( (int) $post_id );
    }

    // =========================================================================
    // Public indexing API.
    // =========================================================================

    public function index_post( WP_Post $post ) {
        global $wpdb;

        $fields = $this->extract_fields( $post );

        $tokens_by_field = [
            'title'   => $this->tokeniser->tokenise( $fields['title'] ),
            'content' => $this->tokeniser->tokenise( $fields['content'] ),
            'excerpt' => $this->tokeniser->tokenise( $fields['excerpt'] ),
        ];

        $total_tokens = count( $tokens_by_field['title'] )
                      + count( $tokens_by_field['content'] )
                      + count( $tokens_by_field['excerpt'] );

        $content_hash = md5( $fields['title'] . '|' . $fields['content'] . '|' . $fields['excerpt'] );

        $documents_table = $wpdb->prefix . 'mbrisa_documents';
        $existing        = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT doc_id, content_hash FROM {$documents_table} WHERE post_id = %d",
                $post->ID
            )
        );

        if ( $existing && $existing->content_hash === $content_hash ) {
            return;
        }

        $ui_excerpt = $this->make_ui_excerpt( $fields['content'] );

        if ( $existing ) {
            $doc_id = (int) $existing->doc_id;

            $wpdb->update(
                $documents_table,
                [
                    'post_type'    => $post->post_type,
                    'title'        => mb_substr( (string) $fields['title'], 0, 500 ),
                    'excerpt'      => mb_substr( $ui_excerpt, 0, 500 ),
                    'url'          => mb_substr( get_permalink( $post ), 0, 500 ),
                    'token_count'  => $total_tokens,
                    'content_hash' => $content_hash,
                    'indexed_at'   => current_time( 'mysql' ),
                ],
                [ 'doc_id' => $doc_id ],
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ],
                [ '%d' ]
            );

            $wpdb->delete(
                $wpdb->prefix . 'mbrisa_postings',
                [ 'doc_id' => $doc_id ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $documents_table,
                [
                    'post_id'      => $post->ID,
                    'post_type'    => $post->post_type,
                    'title'        => mb_substr( (string) $fields['title'], 0, 500 ),
                    'excerpt'      => mb_substr( $ui_excerpt, 0, 500 ),
                    'url'          => mb_substr( get_permalink( $post ), 0, 500 ),
                    'token_count'  => $total_tokens,
                    'content_hash' => $content_hash,
                    'indexed_at'   => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );
            $doc_id = (int) $wpdb->insert_id;
        }

        if ( ! $doc_id ) {
            return;
        }

        foreach ( $tokens_by_field as $field => $tokens ) {
            $this->insert_postings( $doc_id, $field, $tokens );
        }

        $this->recalculate_document_frequencies_for_doc( $doc_id );
        $this->refresh_index_status();
    }

    public function remove_post( $post_id ) {
        global $wpdb;

        $documents_table = $wpdb->prefix . 'mbrisa_documents';
        $doc_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT doc_id FROM {$documents_table} WHERE post_id = %d", $post_id )
        );

        if ( ! $doc_id ) {
            return;
        }

        $affected_term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->prefix}mbrisa_postings WHERE doc_id = %d",
                $doc_id
            )
        );

        $wpdb->delete( $wpdb->prefix . 'mbrisa_postings',  [ 'doc_id' => $doc_id ], [ '%d' ] );
        $wpdb->delete( $documents_table,                    [ 'doc_id' => $doc_id ], [ '%d' ] );

        $this->recalculate_document_frequencies_for_terms( $affected_term_ids );
        $this->prune_orphaned_terms();
        $this->refresh_index_status();
    }

    public function full_reindex() {
        global $wpdb;

        $start = microtime( true );

        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_postings"  );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_documents" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_terms"     );

        $post_types = $this->get_enabled_post_types();
        $count      = 0;

        $paged = 1;
        do {
            $posts = get_posts( [
                'post_type'        => $post_types,
                'post_status'      => 'publish',
                'posts_per_page'   => 50,
                'paged'            => $paged,
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => true,
            ] );

            foreach ( $posts as $post ) {
                $this->index_post( $post );
                $count++;
            }

            $paged++;
        } while ( count( $posts ) === 50 );

        $this->recalculate_all_document_frequencies();
        $this->refresh_index_status();

        return [
            'documents' => $count,
            'duration'  => round( microtime( true ) - $start, 3 ),
        ];
    }

    // =========================================================================
    // Search.
    // =========================================================================

    public function search( $query, $limit = 10 ) {
        global $wpdb;

        $query_tokens = array_values( array_unique( $this->tokeniser->tokenise( $query ) ) );
        if ( empty( $query_tokens ) ) {
            return [
                'results' => [],
                'trace'   => [ 'query_tokens' => [], 'note' => 'Query produced no tokens after cleaning.' ],
            ];
        }

        $w_title   = (float) ( $this->settings['field_weight_title']   ?? 3.0 );
        $w_body    = (float) ( $this->settings['field_weight_body']    ?? 1.0 );
        $w_excerpt = (float) ( $this->settings['field_weight_excerpt'] ?? 1.5 );

        $field_weights = [
            'title'   => $w_title,
            'content' => $w_body,
            'excerpt' => $w_excerpt,
        ];

        $term_rows = $this->lookup_terms( $query_tokens );
        if ( empty( $term_rows ) ) {
            return [
                'results' => [],
                'trace'   => [ 'query_tokens' => $query_tokens, 'note' => 'No query terms found in index.' ],
            ];
        }

        $total_docs   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_documents" );
        $avg_doc_len  = (float) $wpdb->get_var(
            "SELECT AVG(token_count) FROM {$wpdb->prefix}mbrisa_documents WHERE token_count > 0"
        );
        if ( $avg_doc_len <= 0 ) {
            $avg_doc_len = 1.0;
        }

        $doc_lengths = $wpdb->get_results(
            "SELECT doc_id, token_count FROM {$wpdb->prefix}mbrisa_documents",
            OBJECT_K
        );
        $doc_lengths_map = [];
        foreach ( $doc_lengths as $d_id => $row ) {
            $doc_lengths_map[ (int) $d_id ] = (int) $row->token_count;
        }

        $combined_scores = [];
        $per_field_trace = [];

        foreach ( $field_weights as $field => $weight ) {
            if ( $weight <= 0 ) {
                continue;
            }

            $term_stats = $this->build_term_stats_for_field( $term_rows, $field, $total_docs );

            $field_scores = $this->bm25->score_documents( $query_tokens, $term_stats, $doc_lengths_map, $avg_doc_len );

            $per_field_trace[ $field ] = [
                'matched_docs' => count( $field_scores ),
                'top_score'    => ! empty( $field_scores ) ? reset( $field_scores ) : 0.0,
            ];

            foreach ( $field_scores as $doc_id => $score ) {
                if ( ! isset( $combined_scores[ $doc_id ] ) ) {
                    $combined_scores[ $doc_id ] = 0.0;
                }
                $combined_scores[ $doc_id ] += $weight * $score;
            }
        }

        arsort( $combined_scores, SORT_NUMERIC );
        $top = array_slice( $combined_scores, 0, $limit, true );

        if ( empty( $top ) ) {
            return [
                'results' => [],
                'trace'   => [
                    'query_tokens' => $query_tokens,
                    'per_field'    => $per_field_trace,
                    'note'         => 'Terms found in index but no documents matched.',
                ],
            ];
        }

        $doc_ids_placeholder = implode( ',', array_fill( 0, count( $top ), '%d' ) );
        $doc_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT doc_id, post_id, post_type, title, excerpt, url FROM {$wpdb->prefix}mbrisa_documents WHERE doc_id IN ($doc_ids_placeholder)",
                ...array_keys( $top )
            ),
            OBJECT_K
        );

        $results = [];
        foreach ( $top as $doc_id => $score ) {
            if ( ! isset( $doc_rows[ $doc_id ] ) ) {
                continue;
            }
            $row       = $doc_rows[ $doc_id ];
            $results[] = [
                'doc_id'    => (int) $row->doc_id,
                'post_id'   => (int) $row->post_id,
                'post_type' => $row->post_type,
                'title'     => $row->title,
                'excerpt'   => $row->excerpt,
                'url'       => $row->url,
                'score'     => round( (float) $score, 4 ),
            ];
        }

        return [
            'results' => $results,
            'trace'   => [
                'query_tokens'    => $query_tokens,
                'total_documents' => $total_docs,
                'avg_doc_length'  => round( $avg_doc_len, 2 ),
                'per_field'       => $per_field_trace,
            ],
        ];
    }

    public function set_last_full_index_now() {
        $status = get_option( 'mbr_isa_index_status', [] );
        $status['last_full_index'] = current_time( 'mysql' );
        update_option( 'mbr_isa_index_status', $status );
    }

    // =========================================================================
    // Internals — posting/term management.
    // =========================================================================

    private function insert_postings( $doc_id, $field, array $tokens ) {
        if ( empty( $tokens ) ) {
            return;
        }

        global $wpdb;

        $tf_map = array_count_values( $tokens );

        $term_id_map = $this->ensure_terms_exist( array_keys( $tf_map ) );

        $values_sql  = [];
        $values_args = [];
        foreach ( $tf_map as $term => $tf ) {
            if ( ! isset( $term_id_map[ $term ] ) ) {
                continue;
            }
            $values_sql[]  = '(%d, %d, %d, %s)';
            $values_args[] = $term_id_map[ $term ];
            $values_args[] = $doc_id;
            $values_args[] = $tf;
            $values_args[] = $field;
        }

        if ( empty( $values_sql ) ) {
            return;
        }

        $sql = "INSERT INTO {$wpdb->prefix}mbrisa_postings (term_id, doc_id, term_frequency, field) VALUES "
             . implode( ', ', $values_sql );

        $wpdb->query( $wpdb->prepare( $sql, $values_args ) );
    }

    private function ensure_terms_exist( array $terms ) {
        global $wpdb;

        if ( empty( $terms ) ) {
            return [];
        }

        $terms = array_values( array_unique( $terms ) );

        $placeholder = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
        $existing = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, term FROM {$wpdb->prefix}mbrisa_terms WHERE term IN ($placeholder)",
                ...$terms
            )
        );

        $map = [];
        foreach ( $existing as $row ) {
            $map[ $row->term ] = (int) $row->term_id;
        }

        $missing = array_diff( $terms, array_keys( $map ) );
        foreach ( $missing as $term ) {
            $term = mb_substr( $term, 0, 100 );
            $wpdb->insert(
                $wpdb->prefix . 'mbrisa_terms',
                [ 'term' => $term, 'document_frequency' => 0 ],
                [ '%s', '%d' ]
            );
            if ( $wpdb->insert_id ) {
                $map[ $term ] = (int) $wpdb->insert_id;
            }
        }

        return $map;
    }

    private function lookup_terms( array $terms ) {
        global $wpdb;

        if ( empty( $terms ) ) {
            return [];
        }

        $placeholder = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, term, document_frequency FROM {$wpdb->prefix}mbrisa_terms WHERE term IN ($placeholder)",
                ...$terms
            )
        );

        $by_term = [];
        foreach ( $rows as $row ) {
            $by_term[ $row->term ] = $row;
        }

        return $by_term;
    }

    private function build_term_stats_for_field( array $term_rows, $field, $total_docs ) {
        global $wpdb;

        if ( empty( $term_rows ) ) {
            return [];
        }

        $term_ids = array_map( function( $r ) { return (int) $r->term_id; }, $term_rows );
        $placeholder = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, doc_id, term_frequency
                 FROM {$wpdb->prefix}mbrisa_postings
                 WHERE field = %s AND term_id IN ($placeholder)",
                array_merge( [ $field ], $term_ids )
            )
        );

        $postings_by_term_id = [];
        foreach ( $rows as $row ) {
            $tid = (int) $row->term_id;
            if ( ! isset( $postings_by_term_id[ $tid ] ) ) {
                $postings_by_term_id[ $tid ] = [];
            }
            $postings_by_term_id[ $tid ][ (int) $row->doc_id ] = (int) $row->term_frequency;
        }

        $term_stats = [];
        foreach ( $term_rows as $term_string => $row ) {
            $tid      = (int) $row->term_id;
            $postings = isset( $postings_by_term_id[ $tid ] ) ? $postings_by_term_id[ $tid ] : [];
            $field_df = count( $postings );
            $idf      = $this->bm25->calculate_idf( $total_docs, $field_df );

            $term_stats[ $term_string ] = [
                'idf'      => $idf,
                'postings' => $postings,
            ];
        }

        return $term_stats;
    }

    private function recalculate_document_frequencies_for_doc( $doc_id ) {
        global $wpdb;

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->prefix}mbrisa_postings WHERE doc_id = %d",
                $doc_id
            )
        );

        $this->recalculate_document_frequencies_for_terms( $term_ids );
    }

    private function recalculate_document_frequencies_for_terms( array $term_ids ) {
        global $wpdb;

        $term_ids = array_map( 'intval', array_filter( $term_ids ) );
        if ( empty( $term_ids ) ) {
            return;
        }

        $placeholder = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, COUNT(DISTINCT doc_id) AS df
                 FROM {$wpdb->prefix}mbrisa_postings
                 WHERE term_id IN ($placeholder)
                 GROUP BY term_id",
                ...$term_ids
            )
        );

        $counts = [];
        foreach ( $rows as $row ) {
            $counts[ (int) $row->term_id ] = (int) $row->df;
        }

        foreach ( $term_ids as $term_id ) {
            $df = isset( $counts[ $term_id ] ) ? $counts[ $term_id ] : 0;
            $wpdb->update(
                $wpdb->prefix . 'mbrisa_terms',
                [ 'document_frequency' => $df ],
                [ 'term_id' => $term_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }
    }

    private function recalculate_all_document_frequencies() {
        global $wpdb;

        $wpdb->query(
            "UPDATE {$wpdb->prefix}mbrisa_terms t
             SET document_frequency = (
                 SELECT COUNT(DISTINCT doc_id)
                 FROM {$wpdb->prefix}mbrisa_postings p
                 WHERE p.term_id = t.term_id
             )"
        );
    }

    private function prune_orphaned_terms() {
        global $wpdb;

        $wpdb->query(
            "DELETE t FROM {$wpdb->prefix}mbrisa_terms t
             LEFT JOIN {$wpdb->prefix}mbrisa_postings p ON p.term_id = t.term_id
             WHERE p.term_id IS NULL"
        );
    }

    private function refresh_index_status() {
        global $wpdb;

        $status = get_option( 'mbr_isa_index_status', [] );
        $status['documents'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_documents" );
        $status['terms']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_terms" );
        $status['postings']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_postings" );

        update_option( 'mbr_isa_index_status', $status );
    }

    // =========================================================================
    // Helpers.
    // =========================================================================

    private function extract_fields( WP_Post $post ) {
        return [
            'title'   => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
        ];
    }

    private function make_ui_excerpt( $content ) {
        $plain = wp_strip_all_tags( (string) $content );
        $plain = preg_replace( '/\s+/', ' ', $plain );
        $plain = trim( (string) $plain );
        return mb_substr( $plain, 0, 300 );
    }

    private function is_indexable_post_type( $post_type ) {
        return in_array( $post_type, $this->get_enabled_post_types(), true );
    }

    private function get_enabled_post_types() {
        $types = $this->settings['enabled_post_types'] ?? [ 'post', 'page' ];
        return is_array( $types ) ? $types : [ 'post', 'page' ];
    }
}