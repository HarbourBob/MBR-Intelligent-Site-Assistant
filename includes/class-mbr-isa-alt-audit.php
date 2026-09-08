<?php
/**
 * Alt text audit.
 *
 * Finds images with no alt text and ranks them by how much that actually
 * matters — how many published pages display each one, and where.
 *
 * This is deliberately not an alt text generator. The plugin cannot see
 * images: nothing in it looks at a single pixel. Anything it wrote into an
 * alt field would be inferred from the filename, the caption or the
 * surrounding page, which is not a description of the picture, and wrong alt
 * text is worse than none — a screen reader announcing "hero banner 3" gives
 * a listener noise where silence would have been kinder. A genuinely
 * decorative image is supposed to carry an empty alt attribute, and filling
 * those in makes the page worse rather than better.
 *
 * So this finds and ranks the work, shows everything needed to do it, and
 * lets a human write the sentence. On a typical site the top twenty rows are
 * most of the value.
 *
 * @package MBR_ISA
 * @since   0.9.10
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scans the Media Library for images missing alt text.
 */
class MBR_ISA_Alt_Audit {

    /**
     * Where the last scan's results are kept.
     *
     * An option rather than a transient. The scan is the expensive part and
     * the worklist is something an administrator works through over days, so
     * it should survive an object cache flush rather than vanishing halfway
     * down the list.
     */
    const RESULT_OPTION = 'mbr_isa_alt_audit';

    /**
     * Images examined per scan.
     *
     * The scan itself is cheap — see collect_usage() — but the result is
     * rendered as a table somebody reads, and a list of 2,000 rows is not a
     * worklist, it is a wall. This is a working queue, not an inventory.
     */
    const SCAN_LIMIT = 300;

    /**
     * Published posts read per batch when building the usage map.
     */
    const CONTENT_BATCH = 200;

    /**
     * Meta key recording that somebody decided an image is decorative.
     *
     * Empty _wp_attachment_image_alt cannot carry that decision on its own:
     * the Media Library, importers and a half-finished edit all leave an
     * empty row behind, so an empty value means "nobody has written anything"
     * just as often as it means "this needs nothing written". Without a
     * separate marker the Decorative button writes a value the scan cannot
     * distinguish from the state it was trying to resolve, and the image
     * returns to the worklist on the next scan.
     *
     * The alt meta itself is still written empty, so what a screen reader
     * encounters is unchanged. This records only that the call was made.
     *
     * @since 0.9.17
     */
    const DECORATIVE_META = '_mbr_isa_alt_decorative';

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public function register_hooks() {
        add_action( 'admin_post_mbr_isa_alt_audit_scan', [ $this, 'handle_scan' ] );
        add_action( 'wp_ajax_mbr_isa_save_alt', [ $this, 'handle_save_alt' ] );
    }

    /**
     * Run the scan and store the result.
     *
     * @return void
     */
    public function handle_scan() {
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_die( esc_html__( 'Unauthorised', 'mbr-isa' ) );
        }
        check_admin_referer( 'mbr_isa_alt_audit_scan' );

        /*
         * Marked-decorative images are excluded by default. Listing them on
         * request is what keeps the marker from being a one-way door: a
         * mis-click can be found and undone rather than needing the database.
         */
        $include = ! empty( $_POST['show_decorative'] );

        $result = $this->scan( $include );
        update_option( self::RESULT_OPTION, $result, false );

        wp_safe_redirect( MBR_ISA::diagnostic_url( 'alt-text', [ 'alt-scanned' => 1 ] ) );
        exit;
    }

    /**
     * Save one alt text value from the audit table.
     *
     * Capability is 'edit_post' on the attachment itself rather than a blanket
     * 'manage_options'. Writing alt text is editing that piece of media, and
     * an editor who can do it in the Media Library should be able to do it
     * here; equally, somebody who cannot edit a particular attachment should
     * not be able to reach it through this screen.
     *
     * @return void
     */
    public function handle_save_alt() {
        check_ajax_referer( 'mbr_isa_alt_audit', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

        if ( $post_id <= 0 || 'attachment' !== get_post_type( $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Not an attachment.', 'mbr-isa' ) ], 400 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorised.', 'mbr-isa' ) ], 403 );
        }

        /*
         * sanitize_text_field() rather than anything richer. Alt text is read
         * aloud; markup in it is meaningless at best. The 250-character cap is
         * advisory guidance rather than a standard, but alt text past that
         * length is almost always a caption that has wandered into the wrong
         * field.
         */
        $alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
        $alt = mb_substr( $alt, 0, 250 );

        /*
         * The Decorative button sends this flag; the ordinary Save button does
         * not. Anything else clears the marker, so typing a description into a
         * row previously marked decorative returns it to normal without
         * needing a separate undo, and clearing the field returns it to the
         * worklist rather than hiding it for good.
         */
        $decorative = ! empty( $_POST['decorative'] );

        if ( $decorative ) {
            $alt = '';
            update_post_meta( $post_id, self::DECORATIVE_META, 1 );
        } else {
            delete_post_meta( $post_id, self::DECORATIVE_META );
        }

        update_post_meta( $post_id, '_wp_attachment_image_alt', $alt );

        /*
         * Reindex the image immediately if it now qualifies.
         *
         * Writing alt text can make an image eligible for the search index for
         * the first time, and the point of doing this work is that the
         * assistant can then find it. Waiting for the next full reindex would
         * make the connection between the two invisible.
         */
        if ( class_exists( 'MBR_ISA' ) ) {
            $isa = MBR_ISA::get_instance();
            if ( method_exists( $isa, 'indexer' ) ) {
                $isa->indexer()->on_save_attachment( $post_id );
            }
        }

        wp_send_json_success( [
            'post_id' => $post_id,
            'alt'     => $alt,
            'saved'   => true,
        ] );
    }

    /**
     * Find images with no alt text, ranked by usage.
     *
     * @return array Stored result structure.
     */
    public function scan( $include_decorative = false ) {
        $started = microtime( true );

        $missing = $this->images_missing_alt( $include_decorative );
        $usage   = $this->collect_usage();

        $rows = [];

        foreach ( $missing as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post instanceof WP_Post ) {
                continue;
            }

            $featured = isset( $usage['featured'][ $post_id ] ) ? (int) $usage['featured'][ $post_id ] : 0;
            $content  = isset( $usage['content'][ $post_id ] )  ? count( $usage['content'][ $post_id ] ) : 0;

            $parent_id    = (int) $post->post_parent;
            $parent_title = '';
            if ( $parent_id > 0 ) {
                $parent = get_post( $parent_id );
                if ( $parent instanceof WP_Post ) {
                    $parent_title = (string) $parent->post_title;
                }
            }

            // Where a human should look to decide what the image shows. The
            // first page that displays it beats the attachment parent, which
            // is only the page it happened to be uploaded from.
            $context_id = 0;
            if ( ! empty( $usage['content'][ $post_id ] ) ) {
                $context_id = (int) $usage['content'][ $post_id ][0];
            } elseif ( $featured > 0 && ! empty( $usage['featured_first'][ $post_id ] ) ) {
                $context_id = (int) $usage['featured_first'][ $post_id ];
            } elseif ( $parent_id > 0 ) {
                $context_id = $parent_id;
            }

            $rows[] = [
                'decorative'   => (bool) get_post_meta( $post_id, self::DECORATIVE_META, true ),
                'id'           => $post_id,
                'title'        => (string) $post->post_title,
                'filename'     => basename( (string) get_post_meta( $post_id, '_wp_attached_file', true ) ),
                'caption'      => (string) $post->post_excerpt,
                'description'  => (string) $post->post_content,
                'featured'     => $featured,
                'in_content'   => $content,
                'uses'         => $featured + $content,
                'parent_id'    => $parent_id,
                'parent_title' => $parent_title,
                'context_id'   => $context_id,
            ];
        }

        /*
         * Rank by how many published pages actually show the image.
         *
         * This is the whole point of the panel. A logo on forty pages and a
         * cropped duplicate nobody ever used are both "missing alt text", and
         * a flat alphabetical list of 300 files gives no way to tell them
         * apart. Sorted this way the work that matters is at the top and the
         * long tail of unused uploads — which mostly should not be described
         * at all, because nobody will ever encounter them — sinks.
         *
         * Ties break on recency, since a recent upload is more likely to be
         * live work than something from four years ago.
         */
        usort( $rows, function ( $a, $b ) {
            if ( $a['uses'] !== $b['uses'] ) {
                return $b['uses'] - $a['uses'];
            }
            return $b['id'] - $a['id'];
        } );

        return [
            'generated'      => time(),
            'duration'       => round( microtime( true ) - $started, 2 ),
            'rows'           => array_slice( $rows, 0, self::SCAN_LIMIT ),
            'total_missing'  => count( $missing ),
            'shown'          => min( count( $rows ), self::SCAN_LIMIT ),
            'posts_scanned'  => (int) $usage['posts_scanned'],
            'decorative'     => $this->count_decorative(),
            'showing_decorative' => (bool) $include_decorative,
        ];
    }

    /**
     * Attachment IDs for images with empty or absent alt text.
     *
     * A LEFT JOIN rather than a meta_query with 'NOT EXISTS', because the two
     * are not the same question: an image can have the meta row present and
     * empty, which a NOT EXISTS query reports as described. Empty alt is
     * meaningful on a decorative image, but it is indistinguishable here from
     * one somebody opened and never filled in, so both are listed and the
     * usage ranking is what separates them.
     *
     * @return int[]
     */
    private function images_missing_alt( $include_decorative = false ) {
        global $wpdb;

        $mimes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ];
        $place = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

        $sql = $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
               LEFT JOIN {$wpdb->postmeta} m
                 ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
               LEFT JOIN {$wpdb->postmeta} d
                 ON d.post_id = p.ID AND d.meta_key = '" . self::DECORATIVE_META . "'
              WHERE p.post_type = 'attachment'
                AND p.post_mime_type IN ($place)
                AND ( m.meta_id IS NULL OR TRIM(m.meta_value) = '' )
                " . ( $include_decorative ? '' : 'AND d.meta_id IS NULL' ) . "
              ORDER BY p.ID DESC
              LIMIT %d",
            array_merge( $mimes, [ self::SCAN_LIMIT * 4 ] )
        );

        return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
    }

    /**
     * Build a map of which published posts display which images.
     *
     * The obvious implementation asks, for each image, which posts reference
     * it — and that is the shape that made the PDF eligibility counter a
     * performance problem, because it is one unindexable LIKE scan per file.
     *
     * This inverts it. One pass over published post content, extracting the
     * attachment IDs WordPress writes into the markup, gives usage counts for
     * every image on the site at once. The cost is proportional to the number
     * of published posts rather than to the number of images, and there is no
     * LIKE anywhere in it.
     *
     * Three sources of truth, because the editor has changed hands over the
     * years and a real site's content spans all of them:
     *   - block markup:   wp:image {"id":123}
     *   - editor classes: class="wp-image-123", written by both editors
     *   - galleries:      wp:gallery ids, and the classic [gallery ids="..."]
     *
     * Featured images come from a straight meta query, which is indexed.
     *
     * @return array{featured:array,featured_first:array,content:array,posts_scanned:int}
     */
    private function collect_usage() {
        global $wpdb;

        $featured       = [];
        $featured_first = [];
        $content        = [];

        // --- Featured images ------------------------------------------------
        $thumb_rows = $wpdb->get_results(
            "SELECT m.meta_value AS att_id, m.post_id
               FROM {$wpdb->postmeta} m
               JOIN {$wpdb->posts} p ON p.ID = m.post_id
              WHERE m.meta_key = '_thumbnail_id'
                AND p.post_status = 'publish'"
        );

        foreach ( $thumb_rows as $row ) {
            $att = (int) $row->att_id;
            if ( $att <= 0 ) {
                continue;
            }
            $featured[ $att ] = isset( $featured[ $att ] ) ? $featured[ $att ] + 1 : 1;
            if ( ! isset( $featured_first[ $att ] ) ) {
                $featured_first[ $att ] = (int) $row->post_id;
            }
        }

        // --- Content references ---------------------------------------------
        $offset        = 0;
        $posts_scanned = 0;

        /*
         * The coarse filter is an OR rather than a single 'wp-image-' test.
         *
         * That one class covers most images from both editors, and it was the
         * first thing I reached for — but a classic [gallery] shortcode
         * carries no such class, and neither does a cover block, so filtering
         * on it alone would have silently reported those images as unused and
         * sorted them to the bottom. Being wrong quietly is the worst
         * outcome for a panel whose whole job is ranking.
         */
        $like = "( post_content LIKE '%wp-image-%'"
              . " OR post_content LIKE '%[gallery%'"
              . " OR post_content LIKE '%wp:cover%'"
              . " OR post_content LIKE '%wp:gallery%'"
              . " OR post_content LIKE '%wp:media-text%' )";

        do {
            $batch = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content
                       FROM {$wpdb->posts}
                      WHERE post_status = 'publish'
                        AND post_type NOT IN ('attachment','revision')
                        AND {$like}
                      ORDER BY ID ASC
                      LIMIT %d OFFSET %d",
                    self::CONTENT_BATCH,
                    $offset
                )
            );

            foreach ( $batch as $post ) {
                $posts_scanned++;
                $ids = $this->attachment_ids_in( (string) $post->post_content );

                foreach ( $ids as $att ) {
                    if ( ! isset( $content[ $att ] ) ) {
                        $content[ $att ] = [];
                    }
                    // Capped: the count matters, the full list of forty pages
                    // does not, and the first entry is the one shown as
                    // context.
                    if ( count( $content[ $att ] ) < 5 ) {
                        $content[ $att ][] = (int) $post->ID;
                    }
                }
            }

            $offset += self::CONTENT_BATCH;

            // Bounded. A site with more than 4,000 image-bearing published
            // posts gets a partial but still correctly ranked picture, rather
            // than a timeout.
        } while ( count( $batch ) === self::CONTENT_BATCH && $offset < 4000 );

        // --- Page builder layouts -------------------------------------------
        /*
         * Elementor, Bricks and their kin do not put images in post_content at
         * all — the layout lives in postmeta as JSON, and post_content is
         * often empty or a stale fallback. On a site built with one of them,
         * a scan that reads only post_content concludes that essentially
         * every image is unused, which inverts the ranking and makes the
         * panel worse than useless.
         *
         * The stored JSON carries attachment IDs in the same {"id":123} shape
         * the block editor uses, so the existing extractor reads it as-is.
         */
        $builder_keys = apply_filters( 'mbr_isa_builder_meta_keys', [
            '_elementor_data',
            '_bricks_page_content_2',
        ] );

        $builder_keys = array_values( array_filter( array_map( 'strval', (array) $builder_keys ) ) );

        if ( ! empty( $builder_keys ) ) {
            $key_place = implode( ',', array_fill( 0, count( $builder_keys ), '%s' ) );

            $builder_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.post_id, m.meta_value
                       FROM {$wpdb->postmeta} m
                       JOIN {$wpdb->posts} p ON p.ID = m.post_id
                      WHERE m.meta_key IN ($key_place)
                        AND p.post_status = 'publish'
                      LIMIT 4000",
                    $builder_keys
                )
            );

            foreach ( $builder_rows as $row ) {
                $posts_scanned++;

                foreach ( $this->attachment_ids_in( (string) $row->meta_value ) as $att ) {
                    if ( ! isset( $content[ $att ] ) ) {
                        $content[ $att ] = [];
                    }
                    if ( ! in_array( (int) $row->post_id, $content[ $att ], true )
                        && count( $content[ $att ] ) < 5 ) {
                        $content[ $att ][] = (int) $row->post_id;
                    }
                }
            }
        }

        // --- Bare ID-list meta ----------------------------------------------
        /*
         * Some plugins store a list of attachment IDs as a plain
         * comma-separated string rather than as markup or JSON. WooCommerce's
         * product gallery is the common case: _product_image_gallery holds
         * "12,34,56" and the images are rendered from it at runtime, so they
         * appear in neither post_content nor any JSON layout.
         *
         * Such an image is genuinely on a published page, and without this it
         * reports as unused and sinks to the bottom of the worklist — the same
         * inverted ranking the builder scan above exists to prevent.
         *
         * This is kept separate from the builder keys on purpose. Parsing a
         * bare integer list is only safe where the whole value is known to be
         * attachment IDs; applying the same rule to Elementor's JSON would
         * match every number in the layout.
         */
        $id_list_keys = apply_filters( 'mbr_isa_id_list_meta_keys', [
            '_product_image_gallery',
        ] );

        $id_list_keys = array_values( array_filter( array_map( 'strval', (array) $id_list_keys ) ) );

        if ( ! empty( $id_list_keys ) ) {
            $key_place = implode( ',', array_fill( 0, count( $id_list_keys ), '%s' ) );

            $list_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.post_id, m.meta_value
                       FROM {$wpdb->postmeta} m
                       JOIN {$wpdb->posts} p ON p.ID = m.post_id
                      WHERE m.meta_key IN ($key_place)
                        AND p.post_status = 'publish'
                        AND m.meta_value <> ''
                      LIMIT 4000",
                    $id_list_keys
                )
            );

            foreach ( $list_rows as $row ) {
                $posts_scanned++;

                foreach ( $this->ids_in_list( (string) $row->meta_value ) as $att ) {
                    if ( ! isset( $content[ $att ] ) ) {
                        $content[ $att ] = [];
                    }
                    if ( ! in_array( (int) $row->post_id, $content[ $att ], true )
                        && count( $content[ $att ] ) < 5 ) {
                        $content[ $att ][] = (int) $row->post_id;
                    }
                }
            }
        }

        return [
            'featured'       => $featured,
            'featured_first' => $featured_first,
            'content'        => $content,
            'posts_scanned'  => $posts_scanned,
        ];
    }

    /**
     * How many images somebody has marked decorative.
     *
     * @since 0.9.17
     * @return int
     */
    private function count_decorative() {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                   FROM {$wpdb->postmeta} m
                   JOIN {$wpdb->posts} p ON p.ID = m.post_id
                  WHERE m.meta_key = %s
                    AND p.post_type = 'attachment'",
                self::DECORATIVE_META
            )
        );
    }

    /**
     * Parse a bare comma-separated list of attachment IDs.
     *
     * Only ever applied to meta keys declared through
     * mbr_isa_id_list_meta_keys, whose entire value is a list of attachment
     * IDs. The whole string must parse: anything else is rejected outright
     * rather than salvaged, so a key wrongly added through the filter
     * contributes nothing instead of contributing noise.
     *
     * @since 0.9.17
     * @param string $value Raw meta value.
     * @return int[] Unique attachment IDs.
     */
    private function ids_in_list( $value ) {
        $value = trim( $value );

        if ( '' === $value || ! preg_match( '/^\d+(\s*,\s*\d+)*$/', $value ) ) {
            return [];
        }

        $ids = array_filter( array_map( 'intval', preg_split( '/\s*,\s*/', $value ) ) );

        return array_values( array_unique( $ids ) );
    }

    /**
     * Extract attachment IDs referenced in a block of post content.
     *
     * @param string $html Post content.
     * @return int[] Unique attachment IDs.
     */
    private function attachment_ids_in( $html ) {
        $ids = [];

        // class="wp-image-123" — written by both the block and classic
        // editors, and the most reliable single signal.
        if ( preg_match_all( '/wp-image-(\d+)/', $html, $m ) ) {
            $ids = array_merge( $ids, $m[1] );
        }

        // Block attributes: {"id":123 — covers image blocks whose markup has
        // been altered, and cover/media-text blocks.
        if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $html, $m ) ) {
            $ids = array_merge( $ids, $m[1] );
        }

        // Galleries, block and classic: "ids":[1,2,3] and [gallery ids="1,2"].
        if ( preg_match_all( '/"ids"\s*:\s*\[([\d,\s]+)\]/', $html, $m ) ) {
            foreach ( $m[1] as $list ) {
                $ids = array_merge( $ids, preg_split( '/[,\s]+/', trim( $list ) ) );
            }
        }
        if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([\d,\s]+)["\']/', $html, $m ) ) {
            foreach ( $m[1] as $list ) {
                $ids = array_merge( $ids, preg_split( '/[,\s]+/', trim( $list ) ) );
            }
        }

        $ids = array_filter( array_map( 'intval', $ids ) );

        return array_values( array_unique( $ids ) );
    }

    /**
     * The stored result of the last scan, if any.
     *
     * @return array|null
     */
    public function last_result() {
        $stored = get_option( self::RESULT_OPTION, null );

        return is_array( $stored ) && isset( $stored['rows'] ) ? $stored : null;
    }
}
