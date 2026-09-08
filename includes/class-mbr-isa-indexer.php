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
     * Page heights in points for the PDF currently being indexed.
     *
     * Set by extract_attachment_fields() and consumed a few steps later when
     * chunk rows are written. Empty whenever the extractor could not resolve
     * a height for every page, in which case no vertical offset is stored and
     * links fall back to landing at the top of the page.
     *
     * @var float[]
     */
    private $pdf_page_heights = [];

    /**
     * How far above the estimated position to aim, as a fraction of page
     * height. Roughly one fifth of a page: enough to absorb an ordinary
     * figure or table without landing back at the top for a passage that is
     * genuinely near the bottom.
     */
    const PAGE_TOP_BIAS = 0.18;

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

    /**
     * Memoised results of attachment_is_referenced(), keyed by attachment ID.
     *
     * Holds the ID of the first publicly-readable post found to reference the
     * file, or 0 for none. 0.9.9 widened this from a boolean: an image result
     * wants to link to the page the image appears on, and by the time
     * document_url() needs that page the reference scan has already found it.
     * Storing which post it was turns a second scan into a lookup.
     *
     * @var array<int,int>
     */
    private $attachment_reference_cache = [];

    /**
     * Image MIME types the indexer will accept.
     *
     * SVG is deliberately absent. It is markup rather than a bitmap, its text
     * content is arbitrary and often machine-generated, and sites that allow
     * SVG uploads at all are the ones where the file is least likely to be a
     * photograph with a caption. Add it back with the
     * 'mbr_isa_indexable_image_mimes' filter if a site genuinely wants it.
     *
     * @since 0.9.9
     */
    const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    /**
     * How many candidate referencing posts referring_post_id() will test.
     *
     * The reference scan cannot answer the visibility question in SQL — see
     * referring_post_id() — so it collects candidates and runs each through
     * is_publicly_readable() in PHP. That has to be bounded: without a limit,
     * a file linked from a thousand posts would mean a thousand visibility
     * tests, on a public endpoint, for one result.
     *
     * Overrunning the cap fails closed. If every candidate examined is
     * restricted and a publicly-readable one sits beyond the cap, the file is
     * treated as unreferenced and stays out of the index — a missing search
     * result rather than a disclosure, which is the right way round. Raise it
     * with the 'mbr_isa_pdf_reference_candidates' filter on a site where
     * files are routinely linked from very many places.
     *
     * @since 0.9.8
     */
    const PDF_REFERENCE_CANDIDATES = 25;

    /**
     * Image document rows among the current query's candidates.
     *
     * Populated by floor_image_field_lengths() and consumed a few steps later
     * by the optional image weight, so the set is identified once per query
     * rather than queried for twice.
     *
     * @since 0.9.9
     * @var int[]
     */
    private $image_doc_ids = [];

    /**
     * Memoised results of is_result_visible(), keyed by post ID.
     *
     * @since 0.9.7
     * @var array<int,bool>
     */
    private $visibility_memo = [];

    /**
     * Object-cache group for cross-request visibility answers.
     *
     * Non-persistent by default, which is fine: the memo above carries the
     * within-request saving, and this adds a cross-request one wherever a
     * persistent object cache happens to be installed.
     *
     * @since 0.9.7
     */
    const CACHE_GROUP = 'mbr_isa';

    /**
     * Option holding cached corpus-wide search statistics.
     *
     * @since 0.9.7
     */
    const CORPUS_STATS_OPTION = 'mbr_isa_corpus_stats';

    public function __construct( MBR_ISA_Tokeniser $tokeniser, MBR_ISA_BM25 $bm25, MBR_ISA_PDF_Extractor $pdf_extractor, ?MBR_ISA_Chunker $chunker = null ) {
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
        // Publish gate, plus the password gate. A password-protected post
        // keeps post_status 'publish', so checking status alone would index
        // its full body and serve snippets from it through a public endpoint
        // that performs no capability check. Anything not publicly readable
        // is actively removed rather than merely skipped, so a post that
        // gains a password after indexing drops straight back out.
        if ( ! self::is_publicly_readable( $post ) ) {
            $this->remove_post( $post_id );
            $this->resync_pdf_children( $post_id );
            return;
        }
        if ( ! $this->is_indexable_post_type( $post->post_type ) ) {
            return;
        }
        $this->index_post( $post );

        // A PDF's eligibility depends on its parent, so a parent that has just
        // gained or lost a password (or been unpublished) changes the answer
        // for every file attached to it. Without this, a document attached to
        // a newly password-protected page would stay searchable until the next
        // full reindex.
        $this->resync_pdf_children( $post_id );
    }

    /**
     * Index (or remove) an attachment on upload/edit.
     *
     * Two media types are handled, each with its own toggle and its own
     * visibility setting: PDFs, whose text layer is extracted, and images,
     * which are indexed on their filename and alt text.
     *
     * Anything that fails its gate is actively removed rather than merely
     * skipped, so a file that loses eligibility — the feature switched off,
     * the alt text deleted, the page linking to it unpublished — drops out of
     * the index at the point of the change rather than at the next reindex.
     *
     * @since 0.9.9 Images.
     *
     * @param int $post_id Attachment ID.
     * @return void
     */
    public function on_save_attachment( $post_id ) {
        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
            return;
        }

        $mime = (string) get_post_mime_type( $post );

        if ( 'application/pdf' === $mime ) {
            if ( ! $this->pdf_indexing_enabled()
                || ! $this->is_attachment_publicly_visible( $post, $this->pdf_visibility_mode() ) ) {
                $this->remove_post( (int) $post_id );
                return;
            }

            $this->index_post( $post );
            return;
        }

        if ( $this->is_indexable_image_mime( $mime ) ) {
            if ( ! $this->image_indexing_enabled()
                || ! $this->is_attachment_publicly_visible( $post, $this->image_visibility_mode() )
                || ! $this->image_has_usable_text( $post ) ) {
                $this->remove_post( (int) $post_id );
                return;
            }

            $this->index_post( $post );
            return;
        }

        // Some other file type — video, audio, a zip. Not indexed, and any
        // row from a previous configuration goes.
        $this->remove_post( (int) $post_id );
    }

    public function on_delete_post( $post_id ) {
        $this->remove_post( (int) $post_id );
    }

    // =========================================================================
    // Public indexing API.
    // =========================================================================

    public function index_post( WP_Post $post ) {
        global $wpdb;

        // What sort of thing this row represents: 'text', 'pdf' or 'image'.
        // Stored because post_type alone stopped being enough in 0.9.9 — a
        // PDF and an image are both 'attachment', and the two need different
        // treatment at scoring time and different presentation in the widget.
        $doc_kind = $this->document_kind( $post );

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
        $chunk_tops  = [];
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
                $chunk_tops[]  = $this->estimate_page_top(
                    (int) $p['page'],
                    isset( $p['fraction'] ) ? (float) $p['fraction'] : 0.0
                );
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
                    'doc_kind'     => $doc_kind,
                    'title'        => mb_substr(
                        html_entity_decode( (string) $fields['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                        0,
                        500
                    ),
                    'excerpt'      => mb_substr( $this->make_ui_excerpt( $chunk_text ), 0, 2000 ),
                    'url'          => $doc_url,
                    'token_count'  => $row_token_count,
                    'content_hash' => $content_hash,
                    'is_contents'  => $this->chunker->looks_like_contents( $chunk_text ) ? 1 : 0,
                    'page_number'  => isset( $chunk_pages[ $chunk_index ] ) ? (int) $chunk_pages[ $chunk_index ] : 0,
                    'page_top'     => isset( $chunk_tops[ $chunk_index ] ) ? (int) $chunk_tops[ $chunk_index ] : 0,
                    'indexed_at'   => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ]
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

        /*
         * Orphaned terms are deliberately left behind here.
         *
         * remove_post() runs on every save (index_post() deletes and rewrites),
         * every trash and every delete, and prune_orphaned_terms() is a full
         * anti-join across the whole terms and postings tables. On a large
         * index that turns editing a long post into a multi-second write, and
         * a bulk edit or an import into something far worse.
         *
         * It buys nothing urgent. A term with no postings is invisible to
         * search — lookup_terms() only ever matches terms that have postings —
         * so an orphan costs a row in a table nobody scans, not a wrong
         * answer. The prune now runs on the paths that already walk the whole
         * index: full_reindex() and a non-empty purge batch.
         */
        if ( $refresh_status ) {
            $this->refresh_index_status();
        }
    }

    public function full_reindex() {
        global $wpdb;

        $start = microtime( true );

        // The reindex runs to completion inside one request. On a large PDF
        // library that can be a long one, so ask for as much headroom as the
        // host will give. Both calls are advisory — a host running in safe
        // mode, or with a hard PHP-FPM request ceiling, will refuse them,
        // which is why WP-CLI remains the documented route for big sites.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_postings"  );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_documents" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mbrisa_terms"     );

        // The verified list, not the stored one: a type that is enabled but
        // no longer publicly viewable must not be swept back in by the one
        // path that writes to the index without going through on_save_post().
        $post_types = $this->get_indexable_post_types();
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
                    'has_password'     => false,
                    'posts_per_page'   => 50,
                    'paged'            => $paged,
                    'orderby'          => 'ID',
                    'order'            => 'ASC',
                    'suppress_filters' => true,
                ] );

                foreach ( $posts as $post ) {
                    // has_password should have excluded these already, but the
                    // reindex is the one path that writes to the index without
                    // going through on_save_post(), so the gate is repeated
                    // here rather than trusted to a query argument.
                    if ( ! self::is_publicly_readable( $post ) ) {
                        continue;
                    }
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
                    if ( ! $this->is_attachment_publicly_visible( $pdf, $this->pdf_visibility_mode() ) ) {
                        continue;
                    }
                    $this->index_post( $pdf );
                    $count++;
                }

                $paged++;
            } while ( count( $pdfs ) === 25 );
        }

        // Third pass: images. Batched larger than PDFs — there is no file to
        // open and no text to extract, just post fields and two meta reads —
        // but a Media Library holds far more images than documents, so the
        // pass is the one most likely to be long on an ordinary site.
        if ( $this->image_indexing_enabled() ) {
            $image_mimes    = $this->indexable_image_mimes();
            $image_mode     = $this->image_visibility_mode();

            if ( ! empty( $image_mimes ) ) {
                $paged = 1;
                do {
                    $images = get_posts( [
                        'post_type'        => 'attachment',
                        'post_mime_type'   => $image_mimes,
                        'post_status'      => 'inherit',
                        'posts_per_page'   => 100,
                        'paged'            => $paged,
                        'orderby'          => 'ID',
                        'order'            => 'ASC',
                        'suppress_filters' => true,
                    ] );

                    foreach ( $images as $image ) {
                        if ( ! $this->is_attachment_publicly_visible( $image, $image_mode ) ) {
                            continue;
                        }
                        if ( ! $this->image_has_usable_text( $image ) ) {
                            continue;
                        }
                        $this->index_post( $image );
                        $count++;
                    }

                    $paged++;
                } while ( count( $images ) === 100 );
            }
        }

        // Batched paths pay for the prune, since they are already walking the
        // whole index and are not on anybody's save button.
        $this->prune_orphaned_terms();

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

    public function search( $query, $limit = 10, $phrase = '' ) {
        global $wpdb;

        /*
         * Phrase mode. The index is a bag of words with no positional data, so
         * an exact phrase cannot be resolved from the postings alone. Instead
         * the stems find candidates cheaply and the stored passage text is then
         * checked for the literal phrase — the passage is already held on each
         * chunk row for snippet generation, so this costs no extra query.
         *
         * The filter runs before the chunk collapse, not after. A phrase can
         * easily sit in chunk 3 of a document whose chunk 1 scores higher; had
         * the collapse run first, that document would be discarded on the
         * strength of a chunk that never contained the phrase.
         */
        $phrase = $this->normalise_for_phrase( (string) $phrase );

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

        /*
         * Corpus statistics: total document count and the average length of
         * each field across the whole index.
         *
         * These are properties of the corpus, not of the query, and they only
         * change when the index does — so they are computed once and cached
         * until an index write invalidates them (see refresh_index_status()).
         * Before 0.9.7 the aggregate behind them ran on every visitor query,
         * which is affordable on a small site and an architectural problem on
         * a large one.
         */
        $corpus        = $this->get_corpus_stats();
        $total_docs    = (int) $corpus['total_docs'];
        $field_avg_len = $corpus['field_avg_len'];

        /*
         * Per-field document lengths, derived from the postings table
         * (field length = sum of term frequencies for that doc + field).
         * Each field is normalised against its own length and its own
         * corpus average. Normalising every field against the *total*
         * document length crushes long documents (large PDFs especially):
         * a nine-token title inherits the length penalty of an 8,000-token
         * body, and no field weight can recover from that.
         *
         * Only the documents that actually contain a query term are fetched.
         * score_documents() iterates the postings of the query terms and
         * skips any document with no recorded length, so a document that
         * matches nothing contributes nothing whether its length is known or
         * not — loading the whole table only ever produced rows that were
         * then ignored. The inner query is index-driven on the postings
         * primary key; the outer on its doc_id key.
         */
        // array_values() is load-bearing: $term_rows is keyed by term string,
        // array_map() preserves those keys, and spreading a string-keyed
        // array is a fatal error before PHP 8.1. The plugin supports 7.4.
        $term_ids     = array_values( array_map(
            function ( $r ) { return (int) $r->term_id; },
            $term_rows
        ) );
        $term_id_list = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

        $field_length_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT doc_id, field, SUM(term_frequency) AS field_len
                 FROM {$wpdb->prefix}mbrisa_postings
                 WHERE doc_id IN (
                     SELECT doc_id FROM (
                         SELECT DISTINCT doc_id
                         FROM {$wpdb->prefix}mbrisa_postings
                         WHERE term_id IN ($term_id_list)
                     ) AS candidates
                 )
                 GROUP BY doc_id, field",
                ...$term_ids
            )
        );

        $field_lengths = [];
        foreach ( $field_length_rows as $row ) {
            $field_lengths[ (string) $row->field ][ (int) $row->doc_id ] = (int) $row->field_len;
        }

        $field_lengths = $this->floor_image_field_lengths( $field_lengths, $field_avg_len );

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
            // Only the documents this query actually matched can be demoted,
            // so only those are worth fetching. The index on is_contents made
            // this cheap to run, but it still returned every contents row in
            // the corpus into PHP on every visitor query — a candidate pool
            // is 25 to 400 documents, while a documentation library can hold
            // thousands of contents chunks, none of which the loop below can
            // use unless it is already scored.
            //
            // Integer-cast then interpolated, matching the precedent and the
            // reasoning at the field-length lookup further down this class.
            $scored_ids = implode( ',', array_map( 'intval', array_keys( $combined_scores ) ) );

            $contents_ids = $wpdb->get_col(
                "SELECT doc_id FROM {$wpdb->prefix}mbrisa_documents
                  WHERE is_contents = 1 AND doc_id IN ($scored_ids)"
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

        /*
         * Optional thumb on the scale for images.
         *
         * Left at 1.0 — no adjustment — because after the length floor above
         * an image that outranks a page has generally earned it: its title or
         * alt text matched the query more completely than the page's did.
         * That is a legitimate result and not obviously the wrong answer.
         *
         * But it is a judgement, not a fact, and it depends on what the site
         * is for. A photography portfolio wants images to win; a
         * documentation site wants the page that explains the thing, even
         * when a well-named screenshot matches more tightly. So the knob
         * exists and the default declines to guess.
         *
         * @since 0.9.9
         */
        if ( ! empty( $this->image_doc_ids ) && ! empty( $combined_scores ) ) {
            /**
             * Filter the score multiplier applied to image results.
             *
             * Below 1.0 demotes images relative to pages; above 1.0 promotes
             * them. Applied after BM25 and after the image length floor.
             *
             * @since 0.9.9
             *
             * @param float $weight Default 1.0 (no adjustment).
             */
            $image_weight = (float) apply_filters( 'mbr_isa_image_score_weight', 1.0 );

            if ( 1.0 !== $image_weight ) {
                foreach ( $this->image_doc_ids as $img_doc_id ) {
                    if ( isset( $combined_scores[ $img_doc_id ] ) ) {
                        $combined_scores[ $img_doc_id ] *= $image_weight;
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
        /*
         * In phrase mode most candidates will be rejected by the literal check,
         * so the pre-collapse pool is widened considerably. Without this, a
         * document holding the exact phrase but scoring modestly on the
         * individual stems would fall outside the pool and never be tested.
         */
        $pool_size     = '' !== $phrase ? max( $limit * 40, 400 ) : max( $limit * 5, 25 );
        $candidate_ids = array_slice( array_keys( $combined_scores ), 0, $pool_size, true );

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
                "SELECT doc_id, post_id, chunk_index, post_type, doc_kind, title, excerpt, url, page_number, page_top, is_contents FROM {$wpdb->prefix}mbrisa_documents WHERE doc_id IN ($doc_ids_placeholder)",
                ...$candidate_ids
            ),
            OBJECT_K
        );

        /*
         * Collapse to one chunk per document — but not simply the highest
         * scoring one.
         *
         * A contents page is already demoted (see the penalty above), on the
         * reasoning that a visitor searching for a section title should still
         * be able to find it. That reasoning breaks against the collapse: if
         * the contents chunk survives the penalty and still outranks the
         * chapter it lists, it does not appear *alongside* the chapter, it
         * replaces it — and the chapter becomes unreachable through search,
         * since a document gets one result and that result is a list of
         * headings.
         *
         * Worse, it is self-defeating for exactly the query it was meant to
         * serve: search a chapter title and you land on the line of the
         * contents page naming it, rather than the chapter.
         *
         * So where a document has any matching passage that is not a contents
         * page, that passage represents it. The contents chunk is used only
         * when nothing else in that document matched, which is the behaviour
         * the penalty was always trying to express.
         */
        $prefer_body = ! isset( $this->settings['contents_prefer_body'] )
            || ! empty( $this->settings['contents_prefer_body'] );

        $chosen = [];
        foreach ( $candidate_ids as $doc_id ) {
            if ( ! isset( $doc_rows[ $doc_id ] ) ) {
                continue;
            }
            $row     = $doc_rows[ $doc_id ];
            $post_id = (int) $row->post_id;

            // Phrase mode: the passage or title must contain the literal
            // phrase. Checked before the collapse below.
            if ( '' !== $phrase && ! $this->row_contains_phrase( $row, $phrase ) ) {
                continue;
            }

            $is_contents = ! empty( $row->is_contents );

            if ( ! isset( $chosen[ $post_id ] ) ) {
                // $combined_scores is sorted descending, so the first chunk
                // seen for a post is its best one.
                $chosen[ $post_id ] = [ 'doc_id' => $doc_id, 'contents' => $is_contents ];
                continue;
            }

            // Already have a chunk for this post. Replace it only when the
            // one held is a contents page and this one is not.
            if ( $prefer_body && $chosen[ $post_id ]['contents'] && ! $is_contents ) {
                $chosen[ $post_id ] = [ 'doc_id' => $doc_id, 'contents' => false ];
            }
        }

        /*
         * Second pass: rescue any document still represented by a contents
         * chunk.
         *
         * The loop above can only substitute from the candidate pool, and the
         * pool is the top-scoring chunks across the *whole index* — 50 of
         * them for an ordinary search. That is ample when a query term is
         * distinctive: search "Introduction" and few chunks anywhere compete,
         * so the chapter that answers it is comfortably inside the pool and
         * stands in for its contents line.
         *
         * It fails exactly where a contents page is most tempting. Search
         * "What's new in version 0.9.7" and the terms are everywhere — every
         * guide on the site, most chapters of each. The pool fills with short
         * dense chunks from everywhere, the contents chunk is among them
         * because a list of chapter titles is the densest passage in any
         * document, and the chapter that should have replaced it is ranked
         * 60th and never considered. The document is then represented by its
         * contents page for want of a candidate that was scored but not
         * looked at.
         *
         * $combined_scores holds every chunk that matched, not just the pool,
         * so the replacement is usually sitting right there. Only documents
         * actually facing this are queried, which in practice means none or
         * one.
         */
        if ( $prefer_body ) {
            $stuck = [];
            foreach ( $chosen as $post_id => $pick ) {
                if ( ! empty( $pick['contents'] ) ) {
                    $stuck[] = (int) $post_id;
                }
            }

            if ( ! empty( $stuck ) ) {
                $ph = implode( ',', array_fill( 0, count( $stuck ), '%d' ) );
                $alternatives = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT doc_id, post_id, chunk_index, post_type, doc_kind, title, excerpt, url,
                                page_number, page_top, is_contents
                           FROM {$wpdb->prefix}mbrisa_documents
                          WHERE post_id IN ($ph) AND is_contents = 0",
                        ...$stuck
                    ),
                    OBJECT_K
                );

                $best = [];
                foreach ( $alternatives as $alt_id => $alt ) {
                    $alt_id = (int) $alt_id;
                    $score  = $combined_scores[ $alt_id ] ?? 0;

                    // Scored above zero means it matched the query. A chunk
                    // that matched nothing is not a better answer than the
                    // contents page — it is no answer at all.
                    if ( $score <= 0 ) {
                        continue;
                    }

                    // Phrase mode applies to a replacement as much as to
                    // anything else the pool offered.
                    if ( '' !== $phrase && ! $this->row_contains_phrase( $alt, $phrase ) ) {
                        continue;
                    }

                    $post_id = (int) $alt->post_id;
                    if ( ! isset( $best[ $post_id ] ) || $score > $best[ $post_id ]['score'] ) {
                        $best[ $post_id ] = [ 'doc_id' => $alt_id, 'score' => $score ];
                    }
                }

                foreach ( $best as $post_id => $pick ) {
                    $chosen[ $post_id ]      = [ 'doc_id' => $pick['doc_id'], 'contents' => false ];
                    $doc_rows[ $pick['doc_id'] ] = $alternatives[ $pick['doc_id'] ];
                }
            }
        }

        /*
         * Further passages from the same document.
         *
         * Collapse gives each document one result, which is right: a document
         * competes against other documents, and without it a long PDF would
         * fill the list with itself. But a name or a term mentioned in three
         * places has three answers, and only the best-scoring one was ever
         * reachable — the rest were scored, ranked, and then dropped on the
         * floor. Somebody searching an audit report for a person wants every
         * mention, not the one that happened to score highest.
         *
         * So the collapse stands and the others ride along beneath it, as
         * secondary links on the result rather than as results of their own.
         * Confidence caps still count documents, ranking is untouched, and no
         * document can crowd out another.
         *
         * Built after the substitution above has settled, so an extra can
         * never be the chunk that ended up representing the document.
         *
         * @since 0.9.18
         */
        $extras_max = isset( $this->settings['passage_extras_max'] )
            ? max( 0, (int) $this->settings['passage_extras_max'] )
            : 3;

        $extras = [];

        if ( $extras_max > 0 ) {
            foreach ( $candidate_ids as $doc_id ) {
                if ( ! isset( $doc_rows[ $doc_id ] ) ) {
                    continue;
                }

                $row     = $doc_rows[ $doc_id ];
                $post_id = (int) $row->post_id;

                if ( ! isset( $chosen[ $post_id ] )
                    || (int) $chosen[ $post_id ]['doc_id'] === (int) $doc_id ) {
                    continue;
                }

                if ( count( $extras[ $post_id ] ?? [] ) >= $extras_max ) {
                    continue;
                }

                // A contents page is a poor answer on its own (§ 2.20) and a
                // worse one as a footnote to a better passage.
                if ( ! empty( $row->is_contents ) ) {
                    continue;
                }

                if ( ( $combined_scores[ $doc_id ] ?? 0 ) <= 0 ) {
                    continue;
                }

                if ( '' !== $phrase && ! $this->row_contains_phrase( $row, $phrase ) ) {
                    continue;
                }

                /*
                 * Adjacent chunks overlap by 50 words, so a phrase near a
                 * boundary sits in both and both match. Offering them as two
                 * mentions would show the same sentence twice and look like a
                 * fault. Neighbours of anything already kept are skipped.
                 */
                $kept = [ (int) $doc_rows[ $chosen[ $post_id ]['doc_id'] ]->chunk_index ];
                foreach ( $extras[ $post_id ] ?? [] as $held ) {
                    $kept[] = (int) $doc_rows[ $held ]->chunk_index;
                }

                $index    = (int) $row->chunk_index;
                $adjacent = false;
                foreach ( $kept as $k ) {
                    if ( abs( $k - $index ) <= 1 ) {
                        $adjacent = true;
                        break;
                    }
                }

                if ( $adjacent ) {
                    continue;
                }

                $extras[ $post_id ][] = (int) $doc_id;
            }
        }

        // Re-sort by the score of whichever chunk each document ended up
        // with, since a substitution scores lower than the chunk it replaced.
        $ordered = [];
        foreach ( $chosen as $pick ) {
            $ordered[ $pick['doc_id'] ] = $combined_scores[ $pick['doc_id'] ] ?? 0;
        }
        arsort( $ordered, SORT_NUMERIC );

        /*
         * Final visibility gate.
         *
         * Everything above this point trusts the index. This is the point at
         * which it stops: each surviving row is checked against the live post
         * before it becomes a result, so a row that was legitimately indexed
         * and has since become private, protected, restricted or deleted is
         * discarded rather than served. See is_result_visible().
         *
         * The examination count is capped so a pathological case — an index
         * full of stale rows, a phrase search with a 400-row pool — cannot
         * turn one visitor query into hundreds of post lookups. Hitting the
         * cap costs a shorter result list, never a leak.
         */
        $checked   = 0;
        $max_check = max( 50, $limit * 5 );
        $suppressed = 0;

        $results = [];
        foreach ( array_keys( $ordered ) as $doc_id ) {
            $row     = $doc_rows[ $doc_id ];
            $post_id = (int) $row->post_id;

            if ( $checked >= $max_check ) {
                break;
            }
            $checked++;

            if ( ! $this->is_result_visible( $post_id ) ) {
                $suppressed++;
                continue;
            }

            $results[] = [
                'doc_id'      => (int) $row->doc_id,
                'post_id'     => $post_id,
                'chunk_index' => (int) $row->chunk_index,
                'page_number' => (int) $row->page_number,
                'page_top'    => (int) ( $row->page_top ?? 0 ),
                'post_type'   => $row->post_type,
                'doc_kind'    => (string) ( $row->doc_kind ?? 'text' ),
                'title'       => $row->title,
                'excerpt'     => $row->excerpt,
                'url'         => $row->url,
                'score'       => round( (float) $combined_scores[ $doc_id ], 4 ),
                'extras'      => $this->extra_rows( $extras[ $post_id ] ?? [], $doc_rows, $combined_scores ),
            ];

            if ( count( $results ) >= $limit ) {
                break;
            }
        }

        return [
            'results' => $results,
            'trace'   => [
                'query_tokens'      => $query_tokens,
                'total_documents'   => $total_docs,
                'note'              => 'Documents are passage chunks; results are collapsed to the best chunk per post.',
                'per_field'         => $per_field_trace,
                // A non-zero figure here means the index holds rows whose
                // source is no longer publicly readable. Nothing leaked —
                // they were dropped — but the index wants rebuilding.
                'suppressed_stale'  => $suppressed,
            ],
        ];
    }

    /**
     * Shape the further passages of a document for the responder.
     *
     * These have already passed the same phrase, score and contents tests as
     * the primary result, and they belong to a post whose visibility has just
     * been checked — they are further passages of a document already cleared,
     * not separate documents needing their own gate.
     *
     * @since 0.9.18
     * @param int[] $doc_ids         Chosen extra chunk ids, best first.
     * @param array $doc_rows        Rows keyed by doc id.
     * @param array $combined_scores Scores keyed by doc id.
     * @return array
     */
    private function extra_rows( array $doc_ids, array $doc_rows, array $combined_scores ) {
        $out = [];

        foreach ( $doc_ids as $doc_id ) {
            if ( ! isset( $doc_rows[ $doc_id ] ) ) {
                continue;
            }

            $row = $doc_rows[ $doc_id ];

            $out[] = [
                'doc_id'      => (int) $row->doc_id,
                'chunk_index' => (int) $row->chunk_index,
                'page_number' => (int) $row->page_number,
                'page_top'    => (int) ( $row->page_top ?? 0 ),
                'excerpt'     => $row->excerpt,
                'url'         => $row->url,
                'score'       => round( (float) ( $combined_scores[ $doc_id ] ?? 0 ), 4 ),
            ];
        }

        return $out;
    }

    public function set_last_full_index_now() {
        $status = get_option( 'mbr_isa_index_status', [] );
        $status['last_full_index'] = current_time( 'mysql' );

        // A completed full reindex is what clears the 0.8.2 upgrade warning:
        // every row has now passed the current visibility gates.
        unset( $status['reindex_required'] );

        update_option( 'mbr_isa_index_status', $status );
        delete_transient( 'mbr_isa_notice_082' );
        delete_transient( 'mbr_isa_notice_083' );
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

    /**
     * Corpus-wide statistics used by every search: document count and the
     * average length of each field.
     *
     * Cached in an option rather than a transient. A transient on a site with
     * no persistent object cache is a row in wp_options with an expiry, so
     * the storage is the same either way — but an option is not silently
     * dropped by an object cache under memory pressure, and this value must
     * not quietly become unavailable on the visitor path. Invalidation is by
     * index generation, not by clock: the numbers are exactly right until an
     * index write, and then they are exactly wrong, so an expiry time would
     * only ever be a guess at when that happened.
     *
     * @since 0.9.7
     *
     * @return array{total_docs:int,field_avg_len:array<string,float>}
     */
    /**
     * Remove the brevity windfall that BM25 hands to image rows.
     *
     * BM25 normalises a document's score by its length against the corpus
     * average, so a short document scoring the same raw term match as a long
     * one wins. That is the right instinct almost everywhere — a term in a
     * forty-word paragraph does say more about it than the same term in a
     * forty-page report — and it is catastrophic here.
     *
     * An image row holds a filename and a line of alt text: five to ten
     * tokens, against a corpus average dominated by PDF passage chunks
     * running to several hundred. At the default b of 0.75 that is not a
     * modest edge, it is a multiple, and it applies to every image on the
     * site simultaneously. Left alone, a photograph called
     * prospectus-cover.jpg outranks the prospectus — for the query
     * "prospectus", which the prospectus answers perfectly.
     *
     * So each image row's length is raised to the corpus average for its
     * field, which is the length at which BM25's normalisation term is
     * exactly neutral. The image is neither penalised nor rewarded for being
     * short; it competes on whether its words actually match, which is the
     * only thing it has to say. Longer-than-average rows are left alone —
     * this is a floor, not a clamp, and an image with a genuinely long
     * description has earned whatever its length implies.
     *
     * A demotion multiplier, as used for contents pages, was the other
     * option. It was rejected because it answers a different question: a
     * contents page is a poor answer even when it matches perfectly, whereas
     * an image is a fine answer that was merely being scored on the wrong
     * axis. Fixing the axis is not the same as apologising for the result.
     *
     * Costs one query, and only on sites that have images in the index.
     *
     * @since 0.9.9
     *
     * @param array $field_lengths Per-field, per-document lengths.
     * @param array $field_avg_len Per-field corpus averages.
     * @return array
     */
    private function floor_image_field_lengths( array $field_lengths, array $field_avg_len ) {
        global $wpdb;

        // Nothing to do on the overwhelming majority of installs, and this is
        // the visitor path — so the feature costs sites that do not use it
        // one array read rather than one query.
        $status = get_option( 'mbr_isa_index_status', [] );
        $this->image_doc_ids = [];

        if ( empty( $status['images'] ) ) {
            return $field_lengths;
        }

        // Union of the document IDs that matched, across all fields.
        $matched = [];
        foreach ( $field_lengths as $per_doc ) {
            $matched += $per_doc;
        }

        $doc_ids = array_map( 'intval', array_keys( $matched ) );
        if ( empty( $doc_ids ) ) {
            return $field_lengths;
        }

        // Cast to int above, then interpolated directly: a placeholder list
        // for a set this size is thousands of them, and prepare() would gain
        // nothing over the cast where every value is already an integer.
        $id_list = implode( ',', $doc_ids );

        $image_ids = $wpdb->get_col(
            "SELECT doc_id FROM {$wpdb->prefix}mbrisa_documents
              WHERE doc_kind = 'image' AND doc_id IN ($id_list)"
        );

        if ( empty( $image_ids ) ) {
            return $field_lengths;
        }

        $this->image_doc_ids = array_map( 'intval', $image_ids );

        foreach ( $this->image_doc_ids as $doc_id ) {
            foreach ( $field_lengths as $field => $per_doc ) {
                if ( ! isset( $per_doc[ $doc_id ] ) ) {
                    continue;
                }

                $avg = (float) ( $field_avg_len[ $field ] ?? 0 );

                /**
                 * Filter the length floor applied to an image row's field.
                 *
                 * Return a smaller number to let images keep some of the
                 * short-document advantage, or 0 to disable the floor.
                 *
                 * @since 0.9.9
                 *
                 * @param float  $avg    Corpus average length for this field.
                 * @param string $field  'title', 'content' or 'excerpt'.
                 * @param int    $doc_id Document row being adjusted.
                 */
                $floor = (float) apply_filters( 'mbr_isa_image_length_floor', $avg, $field, $doc_id );

                if ( $per_doc[ $doc_id ] < $floor ) {
                    $field_lengths[ $field ][ $doc_id ] = (int) ceil( $floor );
                }
            }
        }

        return $field_lengths;
    }

    private function get_corpus_stats() {
        global $wpdb;

        $cached = get_option( self::CORPUS_STATS_OPTION, null );

        if ( is_array( $cached )
            && isset( $cached['total_docs'], $cached['field_avg_len'] )
            && is_array( $cached['field_avg_len'] ) ) {
            return $cached;
        }

        $total_docs = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_documents"
        );

        $rows = $wpdb->get_results(
            "SELECT field, SUM(term_frequency) AS total_len, COUNT(DISTINCT doc_id) AS doc_count
             FROM {$wpdb->prefix}mbrisa_postings
             GROUP BY field"
        );

        $field_avg_len = [];
        foreach ( $rows as $row ) {
            $doc_count = (int) $row->doc_count;
            $field_avg_len[ (string) $row->field ] = $doc_count > 0
                ? ( (float) $row->total_len / $doc_count )
                : 1.0;
        }

        $stats = [
            'total_docs'    => $total_docs,
            'field_avg_len' => $field_avg_len,
        ];

        // Autoload off: this is only ever read on a search, so there is no
        // reason for every page load on the site to carry it.
        update_option( self::CORPUS_STATS_OPTION, $stats, false );

        return $stats;
    }

    /**
     * Drop the cached corpus statistics.
     *
     * Called from refresh_index_status(), which already runs after every
     * operation that writes to the index, so there is one place to remember
     * rather than several.
     *
     * @since 0.9.7
     *
     * @return void
     */
    private function invalidate_corpus_stats() {
        delete_option( self::CORPUS_STATS_OPTION );
    }

    private function refresh_index_status() {
        global $wpdb;

        $this->invalidate_corpus_stats();

        $status = get_option( 'mbr_isa_index_status', [] );
        // 'documents' stays the count of distinct indexed posts/pages/PDFs so
        // the Diagnostics figure means what it always meant; 'chunks' is the
        // number of passage rows those documents occupy (v0.8.0+).
        $status['documents'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}mbrisa_documents" );
        $status['chunks']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_documents" );

        // Indexed images, as a count of distinct attachments rather than
        // rows. Read on the visitor path by floor_image_field_lengths(),
        // which uses a zero here to skip its query entirely — so this is not
        // only a Diagnostics figure, it is what keeps the image feature free
        // for sites that do not use it.
        $status['images']    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}mbrisa_documents WHERE doc_kind = 'image'" );
        $status['terms']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_terms" );
        $status['postings']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbrisa_postings" );

        update_option( 'mbr_isa_index_status', $status );
    }

    // =========================================================================
    // Helpers.
    // =========================================================================

    private function extract_fields( WP_Post $post ) {
        if ( 'attachment' === $post->post_type ) {
            if ( $this->is_indexable_image_mime( get_post_mime_type( $post ) ) ) {
                return $this->extract_image_fields( $post );
            }

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
            $body                = $this->pdf_extractor->extract( $file, $this->pdf_max_bytes() );
            $this->pdf_page_heights = $this->pdf_extractor->get_page_heights();
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
     * Build the indexable fields for an image attachment.
     *
     * An image has no text of its own, so everything here is what a human
     * typed about it. Four sources, in descending order of trustworthiness:
     * alt text, caption, description, and the filename.
     *
     * Alt text is deliberately indexed twice — once in the content field and
     * once as the excerpt. It is the only one of the four written for the
     * purpose of describing what is in the picture, and the excerpt field
     * carries a higher weight than the body, so this is how that quality
     * difference is expressed. It also means the snippet shown in the widget
     * is the alt text, which is the most useful thing to show.
     *
     * The filename goes in the content field rather than the title. WordPress
     * already derives post_title from the filename on upload, so on an
     * untouched image the words are in the title field at weight 3 anyway;
     * putting them in the body as well covers the case where somebody has
     * since retitled the image and the original filename still says something
     * useful.
     *
     * @since 0.9.9
     *
     * @param WP_Post $post Attachment post.
     * @return array{title:string,content:string,excerpt:string}
     */
    private function extract_image_fields( WP_Post $post ) {
        $alt         = trim( (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) );
        $caption     = trim( (string) $post->post_excerpt );
        $description = trim( (string) $post->post_content );

        $filename_words = $this->filename_to_words(
            (string) get_post_meta( $post->ID, '_wp_attached_file', true )
        );

        // A filename of IMG_4821 or screenshot-2026-08-18 describes nothing.
        // Left in, it puts 'img' and 'screenshot' into the term dictionary
        // attached to hundreds of documents, where they match any query
        // containing those words and nothing useful.
        if ( $this->filename_is_meaningless( $filename_words ) ) {
            $filename_words = '';
        }

        $content = trim( implode( ' ', array_filter( [
            $alt,
            $caption,
            $description,
            $filename_words,
        ] ) ) );

        $title = trim( (string) $post->post_title );
        if ( '' === $title ) {
            $title = $filename_words;
        }

        return [
            'title'   => $title,
            'content' => $content,
            'excerpt' => '' !== $alt ? $alt : $caption,
        ];
    }

    /**
     * Turn a stored file path into indexable words.
     *
     * Strips the extension, WordPress's generated size suffix (-1024x768),
     * the -scaled suffix it adds to large uploads, and the -e1699887654
     * suffix left by the image editor. Without that first step 'jpg' and
     * 'png' become terms in the dictionary, present on every image on the
     * site and matching any query that happens to mention a file format.
     *
     * @since 0.9.9
     *
     * @param string $path Relative or absolute file path.
     * @return string Space-separated words, possibly empty.
     */
    private function filename_to_words( $path ) {
        $name = basename( (string) $path );

        $name = (string) preg_replace( '/\.[a-z0-9]{1,5}$/i', '', $name );
        $name = (string) preg_replace( '/-\d{2,5}x\d{2,5}$/', '', $name );
        $name = (string) preg_replace( '/-(scaled|rotated|e\d{8,})$/i', '', $name );

        $name = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );
        $name = (string) preg_replace( '/\s+/', ' ', $name );

        return trim( $name );
    }

    /**
     * Whether a filename says nothing about what the image contains.
     *
     * Camera and phone defaults, screenshot names, and the placeholder names
     * browsers and download tools produce. These are extremely common — on
     * most Media Libraries they are the majority — and every one of them
     * indexed adds a high-frequency term that can only ever produce a wrong
     * answer.
     *
     * The test runs on the word form, after filename_to_words() has removed
     * punctuation, so 'IMG_4821.jpg' arrives here as 'img 4821'. Pure numbers
     * are already dropped by the tokeniser, so what matters is whether
     * anything is left once the stock prefix goes.
     *
     * @since 0.9.9
     *
     * @param string $words Output of filename_to_words().
     * @return bool
     */
    private function filename_is_meaningless( $words ) {
        $words = trim( mb_strtolower( (string) $words, 'UTF-8' ) );

        if ( '' === $words ) {
            return true;
        }

        $stock = 'img|dsc|dscn|dscf|dcim|pxl|mvimg|gopr|screenshot|screen shot|screen capture'
               . '|photo|image|picture|pic|untitled|unnamed|download|scan|capture|file|copy|final|temp|tmp';

        // The whole name is a stock prefix plus digits, dates or nothing.
        if ( preg_match( '/^(?:' . $stock . ')(?:[\s\d]*)$/', $words ) ) {
            return true;
        }

        // Stock prefix followed only by a timestamp-ish run, e.g.
        // 'screenshot 2026 08 18 at 09 41 22'.
        if ( preg_match( '/^(?:' . $stock . ')[\s\d]*(?:at[\s\d]*)?$/', $words ) ) {
            return true;
        }

        // Hex or UUID-ish blobs from media managers and CDNs.
        if ( preg_match( '/^[0-9a-f\s-]{16,}$/', $words ) ) {
            return true;
        }

        /**
         * Filter whether a filename is treated as uninformative.
         *
         * @since 0.9.9
         *
         * @param bool   $meaningless Result of the built-in tests.
         * @param string $words       Filename in word form.
         */
        return (bool) apply_filters( 'mbr_isa_filename_is_meaningless', false, $words );
    }

    /**
     * Whether an image carries enough human-written text to be worth a row.
     *
     * The alt requirement is a setting rather than a rule because the honest
     * answer depends on the site. On one that has kept up with accessibility,
     * requiring alt text indexes the images somebody has described and skips
     * the rest, which is exactly right. On one that has not, it would index
     * nothing — so the setting can be turned off, at which point a caption, a
     * description or an informative filename will do instead.
     *
     * @since 0.9.9
     *
     * @param WP_Post $post Attachment.
     * @return bool
     */
    private function image_has_usable_text( WP_Post $post ) {
        $alt = trim( (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) );

        if ( $this->image_requires_alt() ) {
            return '' !== $alt;
        }

        if ( '' !== $alt
            || '' !== trim( (string) $post->post_excerpt )
            || '' !== trim( (string) $post->post_content ) ) {
            return true;
        }

        $words = $this->filename_to_words(
            (string) get_post_meta( $post->ID, '_wp_attached_file', true )
        );

        return '' !== $words && ! $this->filename_is_meaningless( $words );
    }

    /**
     * Resolve the public URL for a document.
     *
     * PDFs link to the file itself, not the attachment page: the file is the
     * thing the visitor asked for and the deep link can open it at the right
     * page.
     *
     * Images are the opposite case. Sending somebody to a bare .jpg drops
     * them on a raw file with no navigation, no context and no way back into
     * the site — technically the resource that matched, and a dead end as an
     * answer. So an image links to the page it appears on: its parent if it
     * has a readable one, otherwise whichever published post the reference
     * scan found linking to it, and the file itself only when neither exists.
     *
     * @since 0.9.9 Image handling.
     *
     * @param WP_Post $post Post or attachment.
     * @return string
     */
    private function document_url( WP_Post $post ) {
        if ( 'attachment' === $post->post_type ) {
            if ( $this->is_indexable_image_mime( get_post_mime_type( $post ) ) ) {
                return $this->image_url( $post );
            }

            $url = wp_get_attachment_url( $post->ID );
            if ( $url ) {
                return $url;
            }
        }
        return (string) get_permalink( $post );
    }

    /**
     * Where an image result should send the visitor.
     *
     * @since 0.9.9
     *
     * @param WP_Post $post Image attachment.
     * @return string
     */
    private function image_url( WP_Post $post ) {
        $url = '';

        /*
         * A referencing post is preferred over the attachment's parent, which
         * is the opposite of the PDF ordering and deliberately so.
         *
         * post_parent records which editor the file was uploaded from, not
         * which page displays it. For a PDF those are nearly always the same
         * page — the one offering the download. For an image they routinely
         * are not: dragging a photo into a page once, even into a block later
         * deleted, sets that page as the parent permanently. The image then
         * appears on a blog post while its parent says About, and a result
         * that links to the parent sends the visitor somewhere valid,
         * readable and entirely wrong.
         *
         * So the reference scan goes first. It has usually already run for
         * the visibility check and is memoised, so in the common case this
         * costs a lookup rather than a scan.
         */
        $referrer = $this->referring_post_id( $post );
        if ( $referrer > 0 ) {
            $url = (string) get_permalink( $referrer );
        }

        // Nothing references it: fall back to the parent, which at least
        // shows the image in some context a person chose.
        if ( '' === $url ) {
            $parent_id = (int) $post->post_parent;
            if ( $parent_id > 0 ) {
                $parent = get_post( $parent_id );
                if ( $parent instanceof WP_Post && self::is_publicly_readable( $parent ) ) {
                    $url = (string) get_permalink( $parent );
                }
            }
        }

        if ( '' === $url ) {
            $url = (string) wp_get_attachment_url( $post->ID );
        }

        /**
         * Filter the URL an image search result links to.
         *
         * @since 0.9.9
         *
         * @param string  $url  Resolved URL.
         * @param WP_Post $post Image attachment.
         */
        return (string) apply_filters( 'mbr_isa_image_result_url', $url, $post );
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
     * Re-evaluate every PDF attached to a post after the parent changed.
     *
     * Cheap no-op when PDF indexing is off or the post has no PDF children.
     *
     * @param int $parent_id Parent post ID.
     * @return void
     */
    private function resync_pdf_children( $parent_id ) {
        $mimes = [];

        if ( $this->pdf_indexing_enabled() ) {
            $mimes[] = 'application/pdf';
        }

        // Images too, since 0.9.9. A page gaining a password takes its
        // attached images out of the index for exactly the reason it takes
        // its attached PDFs out: the parent is what made them public.
        if ( $this->image_indexing_enabled() ) {
            $mimes = array_merge( $mimes, $this->indexable_image_mimes() );
        }

        if ( empty( $mimes ) ) {
            return;
        }

        $children = get_posts( [
            'post_type'        => 'attachment',
            'post_mime_type'   => $mimes,
            'post_status'      => 'inherit',
            'post_parent'      => (int) $parent_id,
            'posts_per_page'   => 100,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ] );

        foreach ( $children as $child_id ) {
            $this->on_save_attachment( (int) $child_id );
        }
    }

    /**
     * Whether a non-attachment post may be indexed and served publicly.
     *
     * This is the single place the plugin decides what "public" means. Every
     * path that writes to the index or reads back out of it goes through it,
     * so a rule added here applies to indexing, to save-time removal and to
     * the search endpoint alike.
     *
     * Four conditions, all necessary:
     *
     *   1. post_status is 'publish'. Drafts, pending, private and scheduled
     *      posts are not public.
     *   2. post_password is empty. A password-protected post is 'publish',
     *      so a status-only check would index its entire body and return
     *      snippets from it to anonymous visitors — the widget's REST
     *      endpoint runs no capability check at query time.
     *   3. The post type is viewable on the front end. Widened in 0.9.7.
     *      Status and password are properties of the row; whether the row is
     *      reachable at all is a property of its type, and a type registered
     *      'public' => false has no permalink for a result to link to. The
     *      check runs here rather than being left to the settings screen,
     *      because a stored setting can outlive the registration it was made
     *      against — a type ticked while its plugin was active stays ticked
     *      after that plugin re-registers it as private.
     *   4. No filter objects. See below.
     *
     * On the filter. WordPress's own model of "published" is narrower than
     * the access control actually running on a site: a membership or
     * paywall plugin routinely keeps post_status 'publish' and
     * post_password empty while refusing the content to anonymous
     * visitors. Nothing in the post row records that, so the indexer cannot
     * infer it — but the plugin imposing the restriction can declare it:
     *
     *     add_filter( 'mbr_isa_can_index_post', function ( $allowed, $post ) {
     *         return my_plugin_is_members_only( $post->ID ) ? false : $allowed;
     *     }, 10, 2 );
     *
     * The filter is deliberately deny-only — it runs after the built-in
     * conditions and only when they have already passed, so returning true
     * from it can never publish a draft or unlock a protected post. A
     * third-party filter can tighten this gate; it cannot open it.
     *
     * @since 0.9.7 Post-type viewability check and the mbr_isa_can_index_post filter.
     *
     * @param WP_Post $post Post to test.
     * @return bool
     */
    public static function is_publicly_readable( WP_Post $post ) {
        if ( 'publish' !== $post->post_status ) {
            return false;
        }
        if ( '' !== (string) $post->post_password ) {
            return false;
        }
        if ( ! self::is_public_post_type( $post->post_type ) ) {
            return false;
        }

        /**
         * Filter whether a post may enter the index and be served publicly.
         *
         * Deny-only: this runs only once the built-in conditions have passed,
         * so returning true cannot loosen them.
         *
         * @since 0.9.7
         *
         * @param bool    $allowed Always true at this point.
         * @param WP_Post $post    The post being tested.
         */
        return (bool) apply_filters( 'mbr_isa_can_index_post', true, $post );
    }

    /**
     * Whether a post type is reachable by an anonymous front-end visitor.
     *
     * is_post_type_viewable() is the canonical test and handles the awkward
     * case that a naive 'public' && 'publicly_queryable' check gets wrong:
     * the built-in 'page' type is public but NOT publicly queryable, because
     * pages are resolved by path rather than by query var.
     *
     * An unregistered type resolves to false, which is the answer we want —
     * a type whose plugin is currently inactive has no template, no
     * permalink and no business being served through search.
     *
     * @since 0.9.7
     *
     * @param string $post_type Post type name.
     * @return bool
     */
    public static function is_public_post_type( $post_type ) {
        $object = get_post_type_object( (string) $post_type );

        if ( ! $object ) {
            return false;
        }

        return (bool) is_post_type_viewable( $object );
    }

    /**
     * Whether an already-indexed row may still be shown to a visitor.
     *
     * The index is a cache of a decision taken at index time, and a cache can
     * go stale. Every path that writes to it re-checks visibility, but the
     * write paths are not the only way content changes hands:
     *
     *   - A membership or access-control plugin can restrict a page without
     *     touching post_status, post_password, or firing save_post in the
     *     way this plugin expects.
     *   - A site can upgrade from a version whose gates were narrower, and
     *     carry that older index until somebody clicks Reindex.
     *   - A post type can be re-registered as private.
     *   - A future change to the indexing rules cannot retract what previous
     *     rules already wrote.
     *
     * In all four cases the row is in the table and the endpoint is public,
     * so without this check the answer is served. Re-testing the live post
     * before the result leaves the server turns a whole class of stale-index
     * disclosure into a non-event: whatever is in the table, only content
     * that passes today's rules today is returned.
     *
     * @since 0.9.7
     *
     * @param int $post_id Post or attachment ID from an indexed row.
     * @return bool
     */
    public function is_result_visible( $post_id ) {
        $post_id = (int) $post_id;

        // Per-request memo. A search returns several chunks of the same post
        // when phrase-matching, and the responder may ask again.
        if ( isset( $this->visibility_memo[ $post_id ] ) ) {
            return $this->visibility_memo[ $post_id ];
        }

        // A purge walks the whole index in one process, so the memo is
        // bounded rather than allowed to grow to the size of the site.
        if ( count( $this->visibility_memo ) > 2000 ) {
            $this->visibility_memo = [];
        }

        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post ) {
            // Deleted, or the row outlived its post. Either way, not ours to serve.
            $this->visibility_memo[ $post_id ] = false;
            return false;
        }

        if ( 'attachment' === $post->post_type ) {
            $mime = (string) get_post_mime_type( $post );

            if ( 'application/pdf' === $mime ) {
                $visible = $this->pdf_indexing_enabled()
                    && $this->is_attachment_visible_cached( $post, $this->pdf_visibility_mode() );
            } elseif ( $this->is_indexable_image_mime( $mime ) ) {
                // The alt-text requirement is re-tested here as well as at
                // index time. Alt text can be deleted from the Media Library
                // without anything re-running the indexer, and an image whose
                // description has been removed should stop being an answer.
                $visible = $this->image_indexing_enabled()
                    && $this->image_has_usable_text( $post )
                    && $this->is_attachment_visible_cached( $post, $this->image_visibility_mode() );
            } else {
                $visible = false;
            }
        } else {
            $visible = self::is_publicly_readable( $post );
        }

        $this->visibility_memo[ $post_id ] = $visible;

        return $visible;
    }

    /**
     * is_attachment_publicly_visible() with a short-lived cache in front of it.
     *
     * The 'linked' mode has to ask whether any published post references the
     * file, which means a LIKE scan of post_content and possibly post_meta.
     * That is fine once per file during indexing and much too expensive once
     * per result on a public endpoint, so the answer is cached.
     *
     * Note the cheap route runs first inside is_attachment_publicly_visible(): a
     * file with a published, unprotected parent returns before the scan is
     * reached, so only unattached-but-linked files pay for it at all. Where a
     * persistent object cache is present the answer is shared between
     * requests; where there is not, the memo above still collapses repeats
     * within a single search.
     *
     * @since 0.9.7
     *
     * @param WP_Post $post Attachment.
     * @return bool
     */
    private function is_attachment_visible_cached( WP_Post $post, $mode = 'linked' ) {
        // The mode is part of the key. Without it, changing the setting on
        // the Diagnostics screen would leave answers computed under the old
        // one in the cache for five minutes — which on a tightened setting
        // means five minutes of serving files that no longer qualify.
        $key = 'att_visible_' . $post->ID . '_' . $mode;
        $hit = wp_cache_get( $key, self::CACHE_GROUP );

        // wp_cache_get() returns false both for a miss and for a stored
        // false, so the stored value is an int and the miss is the literal
        // false.
        if ( false !== $hit ) {
            return 1 === (int) $hit;
        }

        $visible = $this->is_attachment_publicly_visible( $post, $mode );

        wp_cache_set( $key, $visible ? 1 : 0, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

        return $visible;
    }

    /**
     * Remove indexed rows whose source no longer passes the visibility rules.
     *
     * Runs as a cursored batch so it can be driven from cron without holding
     * a request open. The search-time check above means nothing leaks while
     * this is pending — this is the tidy-up that makes the index honest
     * again, not the thing standing between a visitor and private content.
     *
     * @since 0.9.7
     *
     * @param int $after_post_id Cursor: process post IDs greater than this.
     * @param int $batch         How many distinct posts to examine.
     * @return array{examined:int,removed:int,cursor:int,done:bool}
     */
    public function purge_ineligible_documents( $after_post_id = 0, $batch = 200 ) {
        global $wpdb;

        $batch = max( 1, (int) $batch );

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->prefix}mbrisa_documents
                 WHERE post_id > %d
                 ORDER BY post_id ASC
                 LIMIT %d",
                (int) $after_post_id,
                $batch
            )
        );

        $post_ids = array_map( 'intval', (array) $post_ids );

        if ( empty( $post_ids ) ) {
            return [
                'examined' => 0,
                'removed'  => 0,
                'cursor'   => (int) $after_post_id,
                'done'     => true,
            ];
        }

        $removed = 0;
        foreach ( $post_ids as $post_id ) {
            if ( ! $this->is_result_visible( $post_id ) ) {
                // Status refresh deferred to the end of the batch — it runs
                // four COUNT queries and there is no point paying for them
                // once per removal.
                $this->remove_post( $post_id, false );
                $removed++;
            }
        }

        if ( $removed > 0 ) {
            $this->prune_orphaned_terms();
            $this->refresh_index_status();
        }

        return [
            'examined' => count( $post_ids ),
            'removed'  => $removed,
            'cursor'   => (int) end( $post_ids ),
            'done'     => count( $post_ids ) < $batch,
        ];
    }

    /**
     * List the indexed posts that no longer pass the visibility rules.
     *
     * Read-only counterpart to purge_ineligible_documents(), so the effect of
     * a purge can be seen before it happens. Unbounded by design — it is
     * driven from WP-CLI, where a large index is a slow command rather than a
     * timed-out web request.
     *
     * @since 0.9.7
     *
     * @return int[] Post IDs whose indexed rows should be removed.
     */
    public function find_ineligible_documents() {
        global $wpdb;

        $post_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->prefix}mbrisa_documents ORDER BY post_id ASC"
        );

        $stale = [];
        foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
            if ( ! $this->is_result_visible( $post_id ) ) {
                $stale[] = $post_id;
            }
        }

        return $stale;
    }

    /**
     * How many of the given PDF attachments currently pass the visibility rule.
     *
     * Used by the Content Sources screen so the effect of the "Which PDFs"
     * setting is visible before a reindex rather than after one.
     *
     * @param int[] $pdf_ids Attachment IDs to test.
     * @return int
     */
    public function count_eligible_pdfs( array $pdf_ids ) {
        $count = 0;

        foreach ( $pdf_ids as $id ) {
            $post = get_post( (int) $id );
            if ( $post instanceof WP_Post
                && $this->is_attachment_publicly_visible( $post, $this->pdf_visibility_mode() ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * How many of the given images currently pass both image gates.
     *
     * Both, because an image has two ways to be excluded and they fail very
     * differently. The visibility setting is the one an administrator expects
     * to matter; the alt-text requirement is the one that quietly accounts
     * for most of the shortfall on a real site, and it is worth being able to
     * see that on the settings screen rather than inferring it from a reindex
     * that produced fewer rows than expected.
     *
     * @since 0.9.9
     *
     * @param int[] $image_ids Attachment IDs to test.
     * @return array{eligible:int,no_text:int}
     */
    public function count_eligible_images( array $image_ids ) {
        $mode     = $this->image_visibility_mode();
        $eligible = 0;
        $no_text  = 0;

        foreach ( $image_ids as $id ) {
            $post = get_post( (int) $id );

            if ( ! $post instanceof WP_Post ) {
                continue;
            }

            if ( ! $this->is_attachment_publicly_visible( $post, $mode ) ) {
                continue;
            }

            if ( ! $this->image_has_usable_text( $post ) ) {
                $no_text++;
                continue;
            }

            $eligible++;
        }

        return [
            'eligible' => $eligible,
            'no_text'  => $no_text,
        ];
    }

    /**
     * Whether an attachment may be indexed.
     *
     * Attachments carry post_status 'inherit' whatever their parent is doing,
     * so status alone tells us nothing. The question we actually want answered
     * is "did the site owner publish this file", and the honest signal for that
     * is whether some published page points at it.
     *
     * Note that post_parent is NOT that signal, which is what 0.8.2 got wrong.
     * WordPress sets post_parent only when a file is uploaded from inside the
     * post editor. A file uploaded via Media > Add New, imported by a migration
     * tool, or restored from a backup has post_parent 0 permanently — even
     * after it is linked from a dozen published pages. Gating on it alone
     * excluded most legitimately public PDFs.
     *
     * Three modes, passed in by the caller from the setting for its own media
     * type — 'pdf_visibility' or 'image_visibility':
     *
     *   'linked' (default) — indexed if a published, unprotected post either
     *                        owns the file (post_parent) or references it in
     *                        its content or meta.
     *   'attached'         — post_parent only. Strict, and only correct on
     *                        sites where every file is uploaded in the editor.
     *   'all'              — every file of that type in the Media Library.
     *
     * The body of this was is_pdf_publicly_visible() until 0.9.9. Nothing in
     * it was ever PDF-specific — it asks whether something published points
     * at a file — so images use it unchanged, with their own mode passed in.
     * That the two media types get separate settings but share this function
     * is the point: "every PDF in the library" and "every image in the
     * library" are very different propositions, and the definition of
     * published should not be.
     *
     * @since 0.9.9 Takes the mode as an argument; covers images.
     *
     * @param WP_Post $post Attachment to test.
     * @param string  $mode 'linked', 'attached' or 'all'.
     * @return bool
     */
    private function is_attachment_publicly_visible( WP_Post $post, $mode = 'linked' ) {
        $mode = (string) $mode;

        if ( 'all' === $mode ) {
            return true;
        }

        // Route one: a published, unprotected parent.
        //
        // The parent's post type is deliberately NOT required to be one of the
        // indexed types. Whether a post type is searchable is a scope question;
        // whether its attachment is public is a visibility question. Conflating
        // them in 0.8.2 also broke PDF-only search, where no post types are
        // enabled at all and so no parent could ever qualify.
        $parent_id = (int) $post->post_parent;
        if ( $parent_id > 0 ) {
            $parent = get_post( $parent_id );
            if ( $parent instanceof WP_Post && self::is_publicly_readable( $parent ) ) {
                return true;
            }
        }

        // Route two: referenced by published content.
        if ( 'linked' === $mode && $this->referring_post_id( $post ) > 0 ) {
            return true;
        }

        return false;
    }

    /**
     * Whether any published, unprotected post references this attachment.
     *
     * Matches on the stored relative path ("2026/08/prospectus.pdf") rather
     * than the full URL, so the test survives http/https differences, a
     * changed site URL, protocol-relative links and CDN rewriting.
     *
     * Post meta is searched as well as post content, because page builders
     * keep their content there — Elementor in _elementor_data, and others
     * similarly — which would otherwise make every PDF on a builder-built site
     * look unreferenced. Sites that do not need it can turn the meta pass off
     * with the 'mbr_isa_pdf_scan_postmeta' filter.
     *
     * A match in either pass is a candidate, not an answer. The SQL can only
     * test the conditions recorded in the post row, so each candidate is then
     * run through is_publicly_readable() — see first_readable_candidate() for
     * why that cannot be folded into the query.
     *
     * @since 0.9.8 Candidates are re-tested in PHP rather than trusted from SQL.
     * @since 0.9.9 Returns the referring post ID, and covers images as well as
     *              PDFs. An image result links to the page the image is on, so
     *              the identity of the referrer is worth keeping rather than
     *              collapsing to a yes/no and scanning again later.
     *
     * @param WP_Post $post Attachment to test.
     * @return int Post ID of a publicly-readable referrer, or 0.
     */
    private function referring_post_id( WP_Post $post ) {
        global $wpdb;

        $post_id = (int) $post->ID;

        // Memoised: a full reindex asks about the same file more than once,
        // and these are LIKE scans.
        if ( isset( $this->attachment_reference_cache[ $post_id ] ) ) {
            return $this->attachment_reference_cache[ $post_id ];
        }

        /*
         * Featured images first, because a featured image is invisible to
         * everything below and cheaper to find than any of it.
         *
         * The scans that follow look for the file's stored path in post
         * content and post meta. A featured image appears in neither: it is
         * recorded as _thumbnail_id holding the attachment's ID, and the
         * theme renders it from there. So an image displayed at the top of
         * every post on a blog was invisible to this function, and qualified
         * only if its post_parent happened to be readable — which then also
         * became the URL the result linked to, sending visitors to whichever
         * page the file was originally uploaded from.
         *
         * This never mattered for PDFs, since nothing sets a PDF as a
         * featured image, which is why the gap survived until images were
         * indexed. It runs for all attachment types anyway: the query is an
         * exact match on an indexed meta key, so it costs materially less
         * than the LIKE scans it precedes, and cheapest-test-first is the
         * same ordering already used for the parent check.
         */
        $featured = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT m.post_id
                   FROM {$wpdb->postmeta} m
                   JOIN {$wpdb->posts} p ON p.ID = m.post_id
                  WHERE m.meta_key = '_thumbnail_id'
                    AND m.meta_value = %d
                    AND p.post_status = 'publish'
                  LIMIT 25",
                $post_id
            )
        );

        if ( ! empty( $featured ) ) {
            $found = $this->first_readable_candidate( $featured );
            if ( $found > 0 ) {
                $this->attachment_reference_cache[ $post_id ] = (int) $found;
                return (int) $found;
            }
        }

        $relative = get_post_meta( $post_id, '_wp_attached_file', true );
        if ( ! is_string( $relative ) || '' === $relative ) {
            $this->attachment_reference_cache[ $post_id ] = 0;
            return 0;
        }

        // esc_like() first: a filename containing _ or % would otherwise act
        // as a SQL wildcard and match unrelated files.
        $needle = '%' . $wpdb->esc_like( $relative ) . '%';

        /*
         * Which post types count as "something published points at this file".
         *
         * Widened in 0.9.7 from an exclusion list to an inclusion one. The old
         * NOT IN ( 'revision', 'attachment' ) admitted every other type on the
         * site, including ones with no front end at all — so a PDF whose only
         * reference lived in a private CPT, a reusable block or a page-builder
         * template counted as published, and its contents became searchable.
         * That is the same mistake as gating on post_status alone, one level
         * further out: a link is only evidence of publication if the thing
         * doing the linking is itself reachable.
         *
         * All viewable types, not the enabled ones — whether a post type is
         * searchable is a scope question, and this is a visibility question.
         * A PDF linked from a published product page is public whether or not
         * 'product' is ticked under Content Sources.
         */
        $viewable_types = array_values( array_filter(
            get_post_types( [], 'names' ),
            [ __CLASS__, 'is_public_post_type' ]
        ) );
        $viewable_types = array_diff( $viewable_types, [ 'attachment' ] );

        if ( empty( $viewable_types ) ) {
            // No viewable post types at all: nothing can reference anything.
            $this->attachment_reference_cache[ $post_id ] = 0;
            return 0;
        }

        $type_placeholders = implode( ',', array_fill( 0, count( $viewable_types ), '%s' ) );
        $type_values       = array_values( $viewable_types );

        /**
         * Filter how many candidate referencing posts are tested.
         *
         * @since 0.9.8
         *
         * @param int     $limit Default MBR_ISA_Indexer::PDF_REFERENCE_CANDIDATES.
         * @param WP_Post $post  The attachment being tested.
         */
        $limit = (int) apply_filters( 'mbr_isa_pdf_reference_candidates', self::PDF_REFERENCE_CANDIDATES, $post );
        $limit = max( 1, min( 500, $limit ) );

        $candidates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_status = 'publish'
                    AND post_password = ''
                    AND post_type IN ($type_placeholders)
                    AND post_content LIKE %s
                  LIMIT %d",
                array_merge( $type_values, [ $needle, $limit ] )
            )
        );

        $found = $this->first_readable_candidate( $candidates );

        /**
         * Filter whether post meta is scanned for attachment references.
         *
         * @param bool $scan Default true.
         */
        if ( ! $found && apply_filters( 'mbr_isa_pdf_scan_postmeta', true ) ) {
            $candidates = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                      INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                      WHERE p.post_status = 'publish'
                        AND p.post_password = ''
                        AND p.post_type IN ($type_placeholders)
                        AND m.meta_key NOT LIKE '\\_wp\\_%%'
                        AND m.meta_value LIKE %s
                      LIMIT %d",
                    array_merge( $type_values, [ $needle, $limit ] )
                )
            );

            $found = $this->first_readable_candidate( $candidates );
        }

        /*
         * Last resort: match on the filename stem, verified in PHP.
         *
         * The passes above look for the file's stored relative path. Two very
         * common situations defeat that outright.
         *
         * Page builders store their layouts as JSON, and JSON escapes forward
         * slashes — so '2025/12/photo.png' sits in the meta as
         * '2025\/12\/photo.png' and a LIKE for the former never matches.
         * Elementor compounds it by serving images from its own thumbnail
         * cache, at a path containing only the stem and a hash, in which the
         * original path appears nowhere at all.
         *
         * So this pass searches for the stem and then proves the match in
         * PHP, rather than trusting a substring. The verifier requires the
         * stem to sit at a path boundary followed by its own extension or a
         * recognised derivative suffix, which is what stops a separate upload
         * called 'logo-2.png' being read as a reference to 'logo.png' while
         * still recognising 'logo-1024x768.png' as one.
         *
         * Guarded to run only when the precise passes have found nothing, and
         * only for a stem long enough to be distinctive — a three-character
         * stem would gather half the media library as candidates for no
         * benefit.
         */
        if ( ! $found ) {
            $stem = $this->attachment_filename_stem( $relative );

            if ( '' !== $stem && mb_strlen( $stem ) >= 6 ) {
                $stem_like = '%' . $wpdb->esc_like( $stem ) . '%';

                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ID, post_content AS haystack FROM {$wpdb->posts}
                          WHERE post_status = 'publish'
                            AND post_type IN ($type_placeholders)
                            AND post_content LIKE %s
                          LIMIT %d",
                        array_merge( $type_values, [ $stem_like, $limit ] )
                    )
                );

                if ( apply_filters( 'mbr_isa_pdf_scan_postmeta', true ) ) {
                    $meta_rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT p.ID, m.meta_value AS haystack
                               FROM {$wpdb->posts} p
                               JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                              WHERE p.post_status = 'publish'
                                AND p.post_type IN ($type_placeholders)
                                AND m.meta_value LIKE %s
                              LIMIT %d",
                            array_merge( $type_values, [ $stem_like, $limit ] )
                        )
                    );
                    $rows = array_merge( (array) $rows, (array) $meta_rows );
                }

                // Verified first, so an unproven substring never reaches the
                // readability test and never becomes an answer.
                $verified = [];
                foreach ( (array) $rows as $row ) {
                    if ( $this->references_stem( (string) $row->haystack, $stem ) ) {
                        $verified[] = (int) $row->ID;
                    }
                }

                if ( ! empty( $verified ) ) {
                    $found = $this->first_readable_candidate( array_unique( $verified ) );
                }
            }
        }

        $this->attachment_reference_cache[ $post_id ] = (int) $found;

        return $this->attachment_reference_cache[ $post_id ];
    }

    /**
     * Whether any of these candidate posts is publicly readable.
     *
     * The visibility decision belongs in one place, and this is how the PDF
     * reference scan reaches it. The SQL above can test the three conditions
     * that live in the post row — status, password, type — but it cannot run
     * the fourth: mbr_isa_can_index_post is arbitrary PHP holding whatever
     * membership logic the site has, and there is no honest way to express
     * that as a WHERE clause.
     *
     * Left at the SQL gate, a members-only page correctly kept out of the
     * index by that filter still counted as evidence that a PDF it links to
     * was published — so the file's full text was indexed and served to
     * anonymous visitors. That is the gap the filter exists to close, moved
     * one level down into the PDF subsystem. Candidates are therefore
     * retrieved and tested here, so every path in the plugin — post indexing,
     * search-time revalidation, PDF parents and PDF references alike — asks
     * the same question of the same function.
     *
     * Ordering is deliberately left to MySQL. An ORDER BY would force the
     * whole LIKE scan to complete before the LIMIT could apply, and that scan
     * is the expensive half of this operation; without one it stops as soon
     * as the candidate list is full.
     *
     * @since 0.9.8
     *
     * @since 0.9.9 Returns the ID rather than a boolean, so an image result
     *              can link to the page that referenced it.
     *
     * @param array $candidate_ids Post IDs from a reference scan.
     * @return int First publicly-readable candidate, or 0.
     */
    /**
     * The distinctive part of an attachment's filename.
     *
     * @since 0.9.17
     *
     * @param string $relative Stored relative path.
     * @return string
     */
    private function attachment_filename_stem( $relative ) {
        $name = basename( (string) $relative );
        $name = (string) preg_replace( '/\.[a-z0-9]{1,5}$/i', '', $name );

        return trim( $name );
    }

    /**
     * Whether a block of text genuinely references this attachment by stem.
     *
     * @since 0.9.17
     *
     * @param string $haystack Post content or meta value.
     * @param string $stem     Filename stem.
     * @return bool
     */
    private function references_stem( $haystack, $stem ) {
        // JSON-encoded meta escapes forward slashes, and content embedded
        // inside JSON-encoded meta escapes them twice.
        $haystack = str_replace( [ '\\\\/', '\\/' ], '/', (string) $haystack );

        $s = preg_quote( (string) $stem, '#' );

        $pattern = '#[/"\'\s=]' . $s . '(?:'
                 . '\.[a-z0-9]{2,5}'
                 . '|-\d{2,5}x\d{2,5}\.[a-z0-9]{2,5}'
                 . '|-(?:scaled|rotated)\.[a-z0-9]{2,5}'
                 . '|-e\d{8,}\.[a-z0-9]{2,5}'
                 . '|-[a-z0-9]{20,}\.[a-z0-9]{2,5}'
                 . ')#i';

        return 1 === preg_match( $pattern, $haystack );
    }

    private function first_readable_candidate( $candidate_ids ) {
        $candidate_ids = array_map( 'intval', (array) $candidate_ids );

        if ( empty( $candidate_ids ) ) {
            return 0;
        }

        // One query for the batch rather than one per get_post() below. Meta
        // and terms are not primed: is_publicly_readable() reads the post row,
        // and a filter that needs more can ask for it itself.
        if ( function_exists( '_prime_post_caches' ) ) {
            _prime_post_caches( $candidate_ids, false, false );
        }

        /*
         * Ordered before testing, so the answer is stable.
         *
         * The SQL that produced these candidates deliberately carries no
         * ORDER BY: an ordered LIKE scan has to finish before the limit can
         * apply, and that scan is the expensive half of the operation. For
         * deciding *whether* a file is published that is fine, since any
         * readable referrer settles it.
         *
         * It is not fine for deciding *which* post an image result links to.
         * Without an order, an image displayed on several published posts
         * could link to a different one on each request. Sorting the fetched
         * candidates here — most recent first, then by descending ID — costs
         * nothing beyond rows already in memory and makes the choice
         * repeatable.
         *
         * The candidate set itself is still whatever the unordered query
         * returned, so on an image referenced from more posts than the
         * candidate cap the set can vary. In practice that affects site
         * furniture like logos, where no single answer is right anyway.
         */
        usort( $candidate_ids, function ( $a, $b ) {
            $pa = get_post( $a );
            $pb = get_post( $b );

            $da = $pa instanceof WP_Post ? (string) $pa->post_date : '';
            $db = $pb instanceof WP_Post ? (string) $pb->post_date : '';

            if ( $da !== $db ) {
                return strcmp( $db, $da );
            }

            return $b - $a;
        } );

        foreach ( $candidate_ids as $candidate_id ) {
            $candidate = get_post( $candidate_id );

            if ( $candidate instanceof WP_Post && self::is_publicly_readable( $candidate ) ) {
                return $candidate_id;
            }
        }

        return 0;
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
     * Which PDFs qualify: 'linked', 'attached' or 'all'.
     *
     * @return string
     */
    private function pdf_visibility_mode() {
        $mode = isset( $this->settings['pdf_visibility'] )
            ? (string) $this->settings['pdf_visibility']
            : 'linked';

        return in_array( $mode, [ 'linked', 'attached', 'all' ], true ) ? $mode : 'linked';
    }

    /**
     * Whether image indexing is switched on.
     *
     * @since 0.9.9
     *
     * @return bool
     */
    private function image_indexing_enabled() {
        return ! empty( $this->settings['index_images'] );
    }

    /**
     * Which images qualify: 'linked', 'attached' or 'all'.
     *
     * Separate from the PDF setting rather than shared. "Every PDF in the
     * Media Library" is a hundred documents somebody deliberately produced;
     * "every image in the Media Library" is several thousand files including
     * every theme screenshot, every cropped duplicate and every logo anybody
     * ever tried. The two settings look alike and mean very different things.
     *
     * @since 0.9.9
     *
     * @return string
     */
    private function image_visibility_mode() {
        $mode = isset( $this->settings['image_visibility'] )
            ? (string) $this->settings['image_visibility']
            : 'linked';

        return in_array( $mode, [ 'linked', 'attached', 'all' ], true ) ? $mode : 'linked';
    }

    /**
     * Whether an image must have alt text to be indexed.
     *
     * Defaults on, and unset counts as on: a site upgrading into this feature
     * should get the conservative behaviour until somebody decides otherwise.
     *
     * @since 0.9.9
     *
     * @return bool
     */
    private function image_requires_alt() {
        return ! isset( $this->settings['image_require_alt'] )
            || ! empty( $this->settings['image_require_alt'] );
    }

    /**
     * Whether a MIME type is an image the indexer will accept.
     *
     * @since 0.9.9
     *
     * @param string $mime MIME type.
     * @return bool
     */
    private function is_indexable_image_mime( $mime ) {
        return in_array( (string) $mime, $this->indexable_image_mimes(), true );
    }

    /**
     * The image MIME types this site will index.
     *
     * @since 0.9.9
     *
     * @return string[]
     */
    private function indexable_image_mimes() {
        /**
         * Filter which image MIME types are indexable.
         *
         * @since 0.9.9
         *
         * @param string[] $mimes Default MBR_ISA_Indexer::IMAGE_MIME_TYPES.
         */
        $mimes = apply_filters( 'mbr_isa_indexable_image_mimes', self::IMAGE_MIME_TYPES );

        return array_values( array_filter( array_map( 'strval', (array) $mimes ) ) );
    }

    /**
     * Classify a post for the doc_kind column.
     *
     * @since 0.9.9
     *
     * @param WP_Post $post Post or attachment.
     * @return string 'text', 'pdf' or 'image'.
     */
    private function document_kind( WP_Post $post ) {
        if ( 'attachment' !== $post->post_type ) {
            return 'text';
        }

        $mime = (string) get_post_mime_type( $post );

        if ( 'application/pdf' === $mime ) {
            return 'pdf';
        }

        return $this->is_indexable_image_mime( $mime ) ? 'image' : 'text';
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

        // Entities are decoded here for the same reason they are decoded in
        // the tokeniser, and with the same ordering: this text is both what a
        // phrase search is matched against and what a visitor is shown as a
        // snippet. Left encoded, a heading reading "Rules & Targeting" is
        // stored as "Rules &amp; Targeting" — unmatchable by the phrase the
        // visitor actually typed, and ugly when displayed.
        $plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $plain = preg_replace( '/\s+/', ' ', $plain );
        $plain = trim( (string) $plain );
        return mb_substr( $plain, 0, 2000 );
    }

    /**
     * Whether a post type is both enabled and safe to index.
     *
     * Two questions, deliberately separate. Whether a type is *enabled* is a
     * scope question answered by the settings screen. Whether it may be
     * served to anonymous visitors is a visibility question, and from 0.9.7
     * the indexer answers that one itself rather than inheriting the answer
     * from a stored option.
     *
     * The settings screen preserves types that are enabled but not currently
     * registered, so a plugin being temporarily deactivated cannot silently
     * drop content from the configuration (see the Content Sources handler).
     * That is good behaviour for a settings list and a poor foundation for a
     * security property: the stored value was written against whatever the
     * type looked like then, and says nothing about what it looks like now.
     *
     * @param string $post_type Post type name.
     * @return bool
     */
    private function is_indexable_post_type( $post_type ) {
        if ( ! in_array( $post_type, $this->get_enabled_post_types(), true ) ) {
            return false;
        }

        return self::is_public_post_type( $post_type );
    }

    /**
     * The enabled post types exactly as stored.
     *
     * May contain types that are not currently registered or no longer
     * public. Callers that write to or read from the index must pass each
     * one through is_indexable_post_type() (or use
     * get_indexable_post_types()) rather than trusting this list.
     *
     * @return string[]
     */
    private function get_enabled_post_types() {
        $types = $this->settings['enabled_post_types'] ?? [ 'post', 'page' ];
        return is_array( $types ) ? $types : [ 'post', 'page' ];
    }

    /**
     * The enabled post types that are currently safe to index.
     *
     * @since 0.9.7
     *
     * @return string[]
     */
    private function get_indexable_post_types() {
        return array_values( array_filter(
            $this->get_enabled_post_types(),
            [ __CLASS__, 'is_public_post_type' ]
        ) );
    }

    /**
     * Flatten text for literal phrase comparison.
     *
     * Indexed passages have already been through extraction and, for PDFs, a
     * certain amount of whitespace repair, so a phrase typed with single
     * spaces will not match text carrying newlines or runs of spaces. Both
     * sides are therefore collapsed to single spaces and lowercased, and
     * curly quotes are folded to straight ones so a phrase copied out of a
     * word processor still matches.
     *
     * @param string $text
     * @return string
     */
    private function normalise_for_phrase( $text ) {
        $text = (string) $text;
        if ( '' === $text ) {
            return '';
        }

        /*
         * Decoded here as well as at index time, deliberately.
         *
         * Indexing is where the fix belongs, but rows written by an earlier
         * version still hold encoded entities and will until the site is
         * reindexed. Decoding both sides at comparison time means quoted
         * search starts working the moment the files are in place, rather
         * than only after somebody runs a reindex they have not been told
         * they need.
         */
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $text = str_replace(
            [ "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xA0" ],
            [ "'", "'", '"', '"', ' ' ],
            $text
        );

        $text = preg_replace( '/\s+/u', ' ', $text );

        return trim( mb_strtolower( $text, 'UTF-8' ) );
    }

    /**
     * Does this chunk row contain the literal phrase?
     *
     * Title as well as passage, so a phrase that appears only in a document's
     * title still matches — the title is indexed with every chunk, and a
     * visitor quoting a page title reasonably expects to find that page.
     *
     * @param object $row    Document row.
     * @param string $phrase Already normalised.
     * @return bool
     */
    private function row_contains_phrase( $row, $phrase ) {
        /*
         * An empty needle makes strpos() return 0, which is not false, so
         * every row would "contain" it and the filter would silently pass
         * everything. The caller guards against this, but a filter that fails
         * open is worth closing at source rather than relying on the guard
         * surviving a future refactor.
         */
        if ( '' === (string) $phrase ) {
            return false;
        }

        $haystacks = [
            $this->normalise_for_phrase( (string) ( $row->excerpt ?? '' ) ),
            $this->normalise_for_phrase( (string) ( $row->title ?? '' ) ),
        ];

        foreach ( $haystacks as $haystack ) {
            if ( '' !== $haystack && false !== strpos( $haystack, $phrase ) ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Vertical target for a PDF deep link, in points down from the page top.
     *
     * PDF open parameters can scroll to a position with `view=FitH,<top>`,
     * but the position has to come from somewhere and this extractor reads no
     * text coordinates — it recognises the operators that move the text
     * cursor and throws their operands away. So the offset is estimated from
     * how far through the page's words the passage starts, which assumes text
     * is spread evenly down the page.
     *
     * That assumption fails on any page with a figure, a table, or a short
     * final paragraph. The estimate is therefore biased deliberately upward
     * by a margin of the page height, so the passage lands at or below the
     * viewport top rather than above it. A visitor then scrolls down a little
     * to find their text, which is the same direction they would have
     * scrolled from the top of the page — just far less far. Overshooting
     * downward would put the passage off-screen above them, which is worse
     * than the behaviour this replaces.
     *
     * Returns 0 when no height is known, which the responder reads as "no
     * offset" and links to the page alone.
     *
     * @param int   $page     1-based page number.
     * @param float $fraction How far down the page the chunk starts, 0 to 1.
     * @return int Points down from the top of the page, or 0.
     */
    private function estimate_page_top( $page, $fraction ) {
        $height = $this->pdf_page_heights[ $page - 1 ] ?? 0;
        if ( $height <= 0 ) {
            return 0;
        }

        // A chunk starting at the very top needs no offset at all; saying so
        // keeps the link short and avoids forcing a zoom change for nothing.
        if ( $fraction <= 0.05 ) {
            return 0;
        }

        /*
         * Measured downward from the top edge of the page.
         *
         * This matters: the two open parameters that can scroll use opposite
         * origins. `view=FitH,top` takes a coordinate in PDF user space,
         * where y increases upward from the bottom-left. `zoom=scale,left,top`
         * measures from the top-left corner instead. We emit the latter (see
         * MBR_ISA_Responder::build_deep_link), so the value stored here is
         * distance down from the top, and the bias is subtracted rather than
         * added.
         */
        $margin = $height * self::PAGE_TOP_BIAS;
        $top    = ( $height * $fraction ) - $margin;

        // Clamp into the page. Negative would be above the top edge, which is
        // meaningless; past the bottom would scroll beyond the text entirely.
        $top = min( $height, max( 0, $top ) );

        return (int) round( $top );
    }

}