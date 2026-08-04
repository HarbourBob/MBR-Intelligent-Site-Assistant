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
     * @var MBR_ISA_PDF_Extractor
     */
    private $pdf_extractor;

    /**
     * Plugin settings array (cached).
     *
     * @var array
     */
    private $settings;

    /**
     * @var MBR_ISA_Chunker
     */
    private $chunker;

    public function __construct( MBR_ISA_Tokeniser $tokeniser, MBR_ISA_BM25 $bm25, MBR_ISA_PDF_Extractor $pdf_extractor, MBR_ISA_Chunker $chunker = null ) {
        $this->tokeniser     = $tokeniser;
        $this->bm25          = $bm25;
        $this->pdf_extractor = $pdf_extractor;
        $this->settings      = get_option( 'mbr_isa_settings', [] );
        $this->chunker       = $chunker ?: new MBR_ISA_Chunker(
            (int) ( $this->settings['chunk_size_words'] ?? 250 ),
            (int) ( $this->settings['chunk_overlap_words'] ?? 50 )
        );
    }

    public function register_hooks() {
        add_action( 'save_post',    [ $this, 'on_save_post' ], 10, 3 );
        add_action( 'deleted_post', [ $this, 'on_delete_post' ] );
        add_action( 'trashed_post', [ $this, 'on_delete_post' ] );

        // Attachments (PDFs) have their own lifecycle hooks. They are stored
        // with post_status 'inherit', so save_post's publish gate doesn't fit.
        add_action( 'add_attachment',    [ $this, 'on_save_attachment' ] );
        add_action( 'edit_attachment',   [ $this, 'on_save_attachment' ] );
        add_action( 'delete_attachment', [ $this, 'on_delete_post' ] );
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
        // Attachments are handled by on_save_attachment(); ignore them here.
        if ( 'attachment' === $post->post_type ) {
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

    /**
     * Index (or remove) a PDF attachment on upload/edit.
     *
     * @param int $post_id Attachment ID.
     * @return void
     */
    public function on_save_attachment( $post_id ) {
        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
            return;
        }

        // Only PDFs, and only when the feature is switched on.
        if ( ! $this->pdf_indexing_enabled() || 'application/pdf' !== get_post_mime_type( $post ) ) {
            $this->remove_post( (int) $post_id );
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

        $title_tokens   = $this->tokeniser->tokenise( $fields['title'] );
        $excerpt_tokens = $this->tokeniser->tokenise( $fields['excerpt'] );

        // Convert to plain text *before* chunking. Splitting raw HTML lets a
        // chunk boundary fall inside a tag, and the resulting fragment has no
        // opening angle bracket for strip_tags() to match — so SVG icon
        // attributes and similar markup would leak into snippets and be
        // tokenised into the index as searchable words.
        $plain_content = $this->tokeniser->strip_markup( $fields['content'] );

        // Long content is split into overlapping passage chunks. Each chunk
        // becomes its own document row and its own BM25 scoring unit, so a
        // relevant passage deep inside a 30-page PDF competes on equal terms
        // with a short page. Search collapses chunks back to one result per
        // post (see search()).
        //
        // PDF text carries page markers, so chunks from a PDF also record
        // the page they begin on, which lets a result link open the file at
        // that page.
        $chunk_pages = [];
        if ( 'attachment' === $post->post_type
            && false !== strpos( $plain_content, MBR_ISA_PDF_Extractor::PAGE_MARKER ) ) {
            $paged  = $this->chunker->chunk_with_pages(
                $plain_content,
                MBR_ISA_PDF_Extractor::PAGE_MARKER
            );
            $chunks = [];
            foreach ( $paged as $p ) {
                $chunks[]      = $p['text'];
                $chunk_pages[] = (int) $p['page'];
            }
        } else {
            $chunks = $this->chunker->chunk( $plain_content );
        }
        if ( empty( $chunks ) ) {
            $chunks = [ '' ];
        }

        $chunk_token_lists = [];
        $total_tokens      = count( $title_tokens ) + count( $excerpt_tokens );
        foreach ( $chunks as $chunk_text ) {
            $chunk_tokens        = $this->tokeniser->tokenise( $chunk_text );
            $chunk_token_lists[] = $chunk_tokens;
            $total_tokens       += count( $chunk_tokens );
        }

        // A PDF that yields no tokens at all (e.g. scanned/image-only with no
        // usable metadata) is not worth a row — drop any stale entry and bail.
        if ( 'attachment' === $post->post_type && 0 === $total_tokens ) {
            $this->remove_post( $post->ID );
            return;
        }

        $content_hash = $this->content_hash( $post, $fields );
        $doc_url      = mb_substr( $this->document_url( $post ), 0, 500 );

        $documents_table = $wpdb->prefix . 'mbrisa_documents';
        $existing_hash   = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT content_hash FROM {$documents_table} WHERE post_id = %d AND chunk_index = 0",
                $post->ID
            )
        );

        if ( null !== $existing_hash && $existing_hash === $content_hash ) {
            return;
        }

        // The number of chunks can change between saves, so the simplest
        // correct update is delete-and-rewrite for this post. remove_post()
        // also recalculates document frequencies for the affected terms.
        $this->remove_post( $post->ID, false );

        $new_doc_ids = [];

        foreach ( $chunks as $chunk_index => $chunk_text ) {
            $chunk_tokens = $chunk_token_lists[ $chunk_index ];

            // The title is indexed with every chunk so any matching passage
            // also benefits from title relevance. The excerpt field is
            // indexed with chunk 0 only — repeating it would duplicate its
            // postings for no ranking benefit.
            $row_token_count = count( $chunk_tokens )
                             + count( $title_tokens )
                             + ( 0 === $chunk_index ? count( $excerpt_tokens ) : 0 );

            $wpdb->insert(
                $documents_table,
                [
                    'post_id'      => $post->ID,
                    'chunk_index'  => $chunk_index,
                    'post_type'    => $post->post_type,
                    'title'        => mb_substr( (string) $fields['title'], 0, 500 ),
                    'excerpt'      => mb_substr( $this->make_ui_excerpt( $chunk_text ), 0, 2000 ),
                    'url'          => $doc_url,
                    'token_count'  => $row_token_count,
                    'content_hash' => $content_hash,
                    'is_contents'  => $this->chunker->looks_like_contents( $chunk_text ) ? 1 : 0,
                    'page_number'  => isset( $chunk_pages[ $chunk_index ] ) ? (int) $chunk_pages[ $chunk_index ] : 0,
                    'indexed_at'   => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s' ]
            );
            $doc_id = (int) $wpdb->insert_id;

            if ( ! $doc_id ) {
                continue;
            }

            $new_doc_ids[] = $doc_id;

            $this->insert_postings( $doc_id, 'title', $title_tokens );
            $this->insert_postings( $doc_id, 'content', $chunk_tokens );
            if ( 0 === $chunk_index ) {
                $this->insert_postings( $doc_id, 'excerpt', $excerpt_tokens );
            }
        }

        foreach ( $new_doc_ids as $doc_id ) {
            $this->recalculate_document_frequencies_for_doc( $doc_id );
        }
        $this->refresh_index_status();
    }

    public function remove_post( $post_id, $refresh_status = true ) {
        global $wpdb;

        $documents_table = $wpdb->prefix . 'mbrisa_documents';
        $doc_ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare( "SELECT doc_id FROM {$documents_table} WHERE post_id = %d", $post_id )
            )
        );

        if ( empty( $doc_ids ) ) {
            return;
        }

        $placeholder = implode( ',', array_fill( 0, count( $doc_ids ), '%d' ) );

        $affected_term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->prefix}mbrisa_postings WHERE doc_id IN ($placeholder)",
                ...$doc_ids
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}mbrisa_postings WHERE doc_id IN ($placeholder)",
                ...$doc_ids
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$documents_table} WHERE doc_id IN ($placeholder)",
                ...$doc_ids
            )
        );

        $this->recalculate_document_frequencies_for_terms( $affected_term_ids );
        $this->prune_orphaned_terms();
        if ( $refresh_status ) {
            $this->refresh_index_status();
        }
    }

    public function full_reindex() {
        global $wpdb;

        $start = microtime( true );

        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_postings"  );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_documents" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_terms"     );

        $post_types = $this->get_enabled_post_types();
        $count      = 0;

        // An empty list is a valid configuration (e.g. PDF-only search). Skip
        // the loop entirely rather than passing an empty array to get_posts(),
        // which WordPress would fall back to interpreting as 'post'.
        if ( ! empty( $post_types ) ) {
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
        }

        // Second pass: PDF attachments. Smaller batches because text extraction
        // is heavier than reading post fields — keeps memory sane on shared hosting.
        if ( $this->pdf_indexing_enabled() ) {
            $paged = 1;
            do {
                $pdfs = get_posts( [
                    'post_type'        => 'attachment',
                    'post_mime_type'   => 'application/pdf',
                    'post_status'      => 'inherit',
                    'posts_per_page'   => 25,
                    'paged'            => $paged,
                    'orderby'          => 'ID',
                    'order'            => 'ASC',
                    'suppress_filters' => true,
                ] );

                foreach ( $pdfs as $pdf ) {
                    $this->index_post( $pdf );
                    $count++;
                }

                $paged++;
            } while ( count( $pdfs ) === 25 );
        }

        $this->recalculate_all_document_frequencies();
        $this->refresh_index_status();

        // Report what actually reached the database rather than how many
        // items were attempted. If a schema problem makes every insert fail,
        // the reindex must not report success — that turns a loud failure
        // into a silent one.
        $status    = get_option( 'mbr_isa_index_status', [] );
        $documents = (int) ( $status['documents'] ?? 0 );

        return [
            'documents' => $documents,
            'chunks'    => (int) ( $status['chunks'] ?? 0 ),
            'attempted' => $count,
            'failed'    => max( 0, $count - $documents ),
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

        $total_docs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_documents" );

        // Per-field document lengths, derived from the postings table
        // (field length = sum of term frequencies for that doc + field).
        // Each field is normalised against its own length and its own
        // corpus average. Normalising every field against the *total*
        // document length crushes long documents (large PDFs especially):
        // a nine-token title inherits the length penalty of an 8,000-token
        // body, and no field weight can recover from that.
        $field_length_rows = $wpdb->get_results(
            "SELECT doc_id, field, SUM(term_frequency) AS field_len
             FROM {$wpdb->prefix}mbrisa_postings
             GROUP BY doc_id, field"
        );

        $field_lengths = [];
        $field_avg_len = [];
        $field_sums    = [];
        $field_counts  = [];
        foreach ( $field_length_rows as $row ) {
            $f = (string) $row->field;
            $field_lengths[ $f ][ (int) $row->doc_id ] = (int) $row->field_len;
            $field_sums[ $f ]   = ( $field_sums[ $f ] ?? 0 ) + (int) $row->field_len;
            $field_counts[ $f ] = ( $field_counts[ $f ] ?? 0 ) + 1;
        }
        foreach ( $field_sums as $f => $sum ) {
            $field_avg_len[ $f ] = $field_counts[ $f ] > 0 ? $sum / $field_counts[ $f ] : 1.0;
        }

        $combined_scores = [];
        $per_field_trace = [];

        foreach ( $field_weights as $field => $weight ) {
            if ( $weight <= 0 ) {
                continue;
            }

            $term_stats = $this->build_term_stats_for_field( $term_rows, $field, $total_docs );

            $field_scores = $this->bm25->score_documents(
                $query_tokens,
                $term_stats,
                $field_lengths[ $field ] ?? [],
                $field_avg_len[ $field ] ?? 1.0
            );

            $per_field_trace[ $field ] = [
                'matched_docs'     => count( $field_scores ),
                'top_score'        => ! empty( $field_scores ) ? reset( $field_scores ) : 0.0,
                'avg_field_length' => round( $field_avg_len[ $field ] ?? 0, 1 ),
            ];

            foreach ( $field_scores as $doc_id => $score ) {
                if ( ! isset( $combined_scores[ $doc_id ] ) ) {
                    $combined_scores[ $doc_id ] = 0.0;
                }
                $combined_scores[ $doc_id ] += $weight * $score;
            }
        }

        // Contents pages list every heading in a document, which makes them
        // the densest concentration of topic vocabulary in the file — and a
        // useless answer, since the visitor gets section titles rather than
        // prose. Demote them so they surface only when nothing better
        // matches, rather than excluding them outright: a visitor searching
        // for a section title should still be able to find it.
        if ( ! empty( $combined_scores ) ) {
            $contents_ids = $wpdb->get_col(
                "SELECT doc_id FROM {$wpdb->prefix}mbrisa_documents WHERE is_contents = 1"
            );
            if ( ! empty( $contents_ids ) ) {
                $penalty = (float) ( $this->settings['contents_score_penalty'] ?? 0.4 );
                foreach ( $contents_ids as $c_id ) {
                    $c_id = (int) $c_id;
                    if ( isset( $combined_scores[ $c_id ] ) ) {
                        $combined_scores[ $c_id ] *= $penalty;
                    }
                }
            }
        }

        arsort( $combined_scores, SORT_NUMERIC );

        // Chunks are scored as independent documents, so several rows of the
        // same post may rank. Collapse to the single best-scoring chunk per
        // post, keeping enough pre-collapse candidates that the final list
        // can still fill up to $limit distinct posts. The winning chunk's
        // stored excerpt then feeds the snippet builder, so the snippet
        // comes from the passage that actually matched.
        $candidate_ids = array_slice( array_keys( $combined_scores ), 0, max( $limit * 5, 25 ), true );

        if ( empty( $candidate_ids ) ) {
            return [
                'results' => [],
                'trace'   => [
                    'query_tokens' => $query_tokens,
                    'per_field'    => $per_field_trace,
                    'note'         => 'Terms found in index but no documents matched.',
                ],
            ];
        }

        $doc_ids_placeholder = implode( ',', array_fill( 0, count( $candidate_ids ), '%d' ) );
        $doc_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT doc_id, post_id, chunk_index, post_type, title, excerpt, url, page_number FROM {$wpdb->prefix}mbrisa_documents WHERE doc_id IN ($doc_ids_placeholder)",
                ...$candidate_ids
            ),
            OBJECT_K
        );

        $results   = [];
        $seen_post = [];
        foreach ( $candidate_ids as $doc_id ) {
            if ( ! isset( $doc_rows[ $doc_id ] ) ) {
                continue;
            }
            $row     = $doc_rows[ $doc_id ];
            $post_id = (int) $row->post_id;

            // $combined_scores is sorted descending, so the first chunk seen
            // for a post is its best one.
            if ( isset( $seen_post[ $post_id ] ) ) {
                continue;
            }
            $seen_post[ $post_id ] = true;

            $results[] = [
                'doc_id'      => (int) $row->doc_id,
                'post_id'     => $post_id,
                'chunk_index' => (int) $row->chunk_index,
                'page_number' => (int) $row->page_number,
                'post_type'   => $row->post_type,
                'title'       => $row->title,
                'excerpt'     => $row->excerpt,
                'url'         => $row->url,
                'score'       => round( (float) $combined_scores[ $doc_id ], 4 ),
            ];

            if ( count( $results ) >= $limit ) {
                break;
            }
        }

        return [
            'results' => $results,
            'trace'   => [
                'query_tokens'    => $query_tokens,
                'total_documents' => $total_docs,
                'note'            => 'Documents are passage chunks; results are collapsed to the best chunk per post.',
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
        // 'documents' stays the count of distinct indexed posts/pages/PDFs so
        // the Diagnostics figure means what it always meant; 'chunks' is the
        // number of passage rows those documents occupy (v0.8.0+).
        $status['documents'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}mbrisa_documents" );
        $status['chunks']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_documents" );
        $status['terms']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_terms" );
        $status['postings']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_postings" );

        update_option( 'mbr_isa_index_status', $status );
    }

    // =========================================================================
    // Helpers.
    // =========================================================================

    private function extract_fields( WP_Post $post ) {
        if ( 'attachment' === $post->post_type ) {
            return $this->extract_attachment_fields( $post );
        }

        return [
            'title'   => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
        ];
    }

    /**
     * Build the indexable fields for a PDF attachment: extracted body text plus
     * any library metadata (description, caption, alt) as a fallback/supplement.
     *
     * @param WP_Post $post Attachment post.
     * @return array{title:string,content:string,excerpt:string}
     */
    private function extract_attachment_fields( WP_Post $post ) {
        $file = get_attached_file( $post->ID );

        $body = '';
        if ( $file && is_readable( $file ) ) {
            $body = $this->pdf_extractor->extract( $file, $this->pdf_max_bytes() );
        }

        // Media-library metadata: description (post_content), caption
        // (post_excerpt), alt text. Always folded in, so a PDF with no text
        // layer still indexes on whatever the author typed.
        $alt        = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
        $meta_parts = array_filter( [
            (string) $post->post_content,
            (string) $post->post_excerpt,
            $alt,
        ] );

        $content = trim( $body . ' ' . implode( ' ', $meta_parts ) );

        $title = (string) $post->post_title;
        if ( '' === trim( $title ) && $file ) {
            $title = basename( $file );
        }

        return [
            'title'   => $title,
            'content' => $content,
            'excerpt' => (string) $post->post_excerpt,
        ];
    }

    /**
     * Resolve the public URL for a document. PDFs link to the file itself, not
     * the attachment page.
     *
     * @param WP_Post $post Post or attachment.
     * @return string
     */
    private function document_url( WP_Post $post ) {
        if ( 'attachment' === $post->post_type ) {
            $url = wp_get_attachment_url( $post->ID );
            if ( $url ) {
                return $url;
            }
        }
        return (string) get_permalink( $post );
    }

    /**
     * Compute the change-detection hash for a document. For attachments the
     * file size and mtime are folded in so replacing the file (without touching
     * post fields) still triggers a re-index.
     *
     * @param WP_Post $post   Post or attachment.
     * @param array   $fields Extracted fields.
     * @return string 32-char md5.
     */
    private function content_hash( WP_Post $post, array $fields ) {
        $base = $fields['title'] . '|' . $fields['content'] . '|' . $fields['excerpt'];

        if ( 'attachment' === $post->post_type ) {
            $file = get_attached_file( $post->ID );
            if ( $file && is_readable( $file ) ) {
                $base .= '|' . (int) @filesize( $file ) . '|' . (int) @filemtime( $file );
            }
        }

        return md5( $base );
    }

    /**
     * Whether PDF attachment indexing is switched on.
     *
     * @return bool
     */
    private function pdf_indexing_enabled() {
        return ! empty( $this->settings['index_pdfs'] );
    }

    /**
     * Maximum PDF size to attempt, in bytes, from settings (default 20 MB).
     *
     * @return int
     */
    private function pdf_max_bytes() {
        $mb = (int) ( $this->settings['pdf_max_filesize_mb'] ?? 20 );
        if ( $mb <= 0 ) {
            $mb = 20;
        }
        return $mb * 1024 * 1024;
    }

    private function make_ui_excerpt( $content ) {
        $plain = wp_strip_all_tags( (string) $content );
        $plain = preg_replace( '/\s+/', ' ', $plain );
        $plain = trim( (string) $plain );
        return mb_substr( $plain, 0, 2000 );
    }

    private function is_indexable_post_type( $post_type ) {
        return in_array( $post_type, $this->get_enabled_post_types(), true );
    }

    private function get_enabled_post_types() {
        $types = $this->settings['enabled_post_types'] ?? [ 'post', 'page' ];
        return is_array( $types ) ? $types : [ 'post', 'page' ];
    }
}