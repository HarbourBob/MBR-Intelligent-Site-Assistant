<?php
/**
 * WP-CLI commands: wp mbr-isa reindex | status
 *
 * Running the reindex from the CLI sidesteps PHP's web-request
 * max_execution_time and memory ceilings, which is exactly what large
 * sites and large PDF libraries need — see the "Reindex takes a long
 * time or times out" entry in the user guide's troubleshooting chapter.
 *
 * Only loaded when WP-CLI is running; the class does not exist on
 * ordinary web requests.
 *
 * Contains code contributed by James Wilson (Director of Technology,
 * Cogora), whose review and patches to the CLI commands are gratefully
 * acknowledged.
 *
 * @package MBR_ISA
 * @author  Robert Palmer
 * @author  James Wilson <Cogora>
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

/**
 * Manage the MBR Intelligent Site Assistant search index.
 */
class MBR_ISA_CLI_Command {

    /**
     * Run a full reindex.
     *
     * Wipes the index tables and rebuilds them from every published item
     * of the enabled post types, plus PDFs and images if those are switched on.
     * Identical to the "Run Full Reindex" button on the Diagnostics page,
     * but free of web-request time and memory limits.
     *
     * ## EXAMPLES
     *
     *     wp mbr-isa reindex
     *
     * @when after_wp_load
     */
    public function reindex( $args, $assoc_args ) {
        WP_CLI::log( 'Running full reindex…' );

        $indexer = MBR_ISA::get_instance()->indexer();
        $result  = $indexer->full_reindex();
        $indexer->set_last_full_index_now();

        $status = get_option( 'mbr_isa_index_status', [] );

        $failed = (int) ( $result['failed'] ?? 0 );
        $line   = sprintf(
            'Indexed %d document(s) as %d chunk(s) in %ss. Terms: %d. Postings: %d.',
            (int) ( $result['documents'] ?? 0 ),
            (int) ( $result['chunks'] ?? 0 ),
            $result['duration'],
            (int) ( $status['terms'] ?? 0 ),
            (int) ( $status['postings'] ?? 0 )
        );

        if ( $failed > 0 ) {
            WP_CLI::error( sprintf(
                '%s%d of %d items could not be written to the index. The database schema is '
                . 'probably out of date - check `wp mbr-isa status`, then deactivate and '
                . 'reactivate the plugin to re-run the schema upgrade.',
                $line . ' ',
                $failed,
                (int) ( $result['attempted'] ?? 0 )
            ) );
        }

        WP_CLI::success( $line );
    }

    /**
     * Remove indexed rows whose source is no longer publicly readable.
     *
     * Walks the whole index checking each post against the current
     * visibility rules and deleting the rows for any that fail — content
     * that has been unpublished, password-protected, deleted, restricted by
     * an access-control plugin, or whose post type is no longer public.
     *
     * This is housekeeping rather than a security control. The search
     * endpoint re-tests every result against the live post before returning
     * it, so a stale row cannot be served whether or not this has run. Use
     * it after upgrading from an older version to make the index honest
     * without paying for a full rebuild, or to see what the old rules let
     * through.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be removed without removing it.
     *
     * ## EXAMPLES
     *
     *     wp mbr-isa purge
     *     wp mbr-isa purge --dry-run
     *
     * @when after_wp_load
     */
    public function purge( $args, $assoc_args ) {
        $dry_run = ! empty( $assoc_args['dry-run'] );
        $indexer = MBR_ISA::get_instance()->indexer();

        if ( $dry_run ) {
            $stale = $indexer->find_ineligible_documents();

            if ( empty( $stale ) ) {
                WP_CLI::success( 'Nothing to purge — every indexed item still passes the visibility rules.' );
                return;
            }

            foreach ( $stale as $post_id ) {
                $post  = get_post( $post_id );
                $title = $post instanceof WP_Post ? $post->post_title : '(post no longer exists)';
                WP_CLI::log( sprintf( '  %d  %s', $post_id, $title ) );
            }

            WP_CLI::success( sprintf(
                '%d item(s) would be removed. Re-run without --dry-run to remove them.',
                count( $stale )
            ) );
            return;
        }

        $cursor   = 0;
        $examined = 0;
        $removed  = 0;

        do {
            $result   = $indexer->purge_ineligible_documents( $cursor, 200 );
            $cursor   = (int) $result['cursor'];
            $examined += (int) $result['examined'];
            $removed  += (int) $result['removed'];
        } while ( empty( $result['done'] ) );

        // The scheduled pass exists for sites without CLI access. Having done
        // the work here, there is nothing left for it to do.
        wp_clear_scheduled_hook( 'mbr_isa_purge_stale_index' );
        delete_option( 'mbr_isa_purge_cursor' );

        WP_CLI::success( sprintf(
            'Examined %d indexed item(s); removed %d that no longer pass the visibility rules.',
            $examined,
            $removed
        ) );
    }

    /**
     * Show index status and counts.
     *
     * ## EXAMPLES
     *
     *     wp mbr-isa status
     *
     * @when after_wp_load
     */
    public function status() {
        $status   = get_option( 'mbr_isa_index_status', [] );
        $settings = get_option( 'mbr_isa_settings', [] );

        WP_CLI::log( 'Plugin version: ' . MBR_ISA_VERSION );
        WP_CLI::log( 'DB schema:      ' . get_option( 'mbr_isa_db_version', '0' ) );
        WP_CLI::log( 'Documents:      ' . (int) ( $status['documents'] ?? 0 ) );
        WP_CLI::log( 'Chunks:         ' . (int) ( $status['chunks'] ?? 0 ) );
        WP_CLI::log( 'Terms:          ' . (int) ( $status['terms'] ?? 0 ) );
        WP_CLI::log( 'Postings:       ' . (int) ( $status['postings'] ?? 0 ) );
        WP_CLI::log( 'PDF indexing:   ' . ( ! empty( $settings['index_pdfs'] ) ? 'on' : 'off' ) );

        /*
         * Image indexing, with the count of images actually in the index
         * beside it.
         *
         * The count matters more here than it does for PDFs. An image has two
         * independent ways to be excluded — the visibility rule and the alt
         * text requirement — so "on" with a count of zero is a real and fairly
         * common state, and it means something quite specific: the setting is
         * enabled but nothing qualifies, usually because the images have no
         * alt text. Reporting the switch without the count would leave that
         * looking like a broken reindex.
         */
        $image_state = ! empty( $settings['index_images'] ) ? 'on' : 'off';
        if ( 'on' === $image_state ) {
            $image_state .= ' (' . (int) ( $status['images'] ?? 0 ) . ' indexed)';
        }
        WP_CLI::log( 'Image indexing: ' . $image_state );

        WP_CLI::log( 'Last full:      ' . ( $status['last_full_index'] ?? 'never' ) );
    }
}

WP_CLI::add_command( 'mbr-isa', 'MBR_ISA_CLI_Command' );
