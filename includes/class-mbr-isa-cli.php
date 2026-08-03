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
 * @package MBR_ISA
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
     * of the enabled post types, plus PDFs if PDF indexing is switched on.
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
                . 'probably out of date \u2014 check `wp mbr-isa status`, then deactivate and '
                . 'reactivate the plugin to re-run the schema upgrade.',
                $line . ' ',
                $failed,
                (int) ( $result['attempted'] ?? 0 )
            ) );
        }

        WP_CLI::success( $line );
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
        WP_CLI::log( 'Last full:      ' . ( $status['last_full_index'] ?? 'never' ) );
    }
}

WP_CLI::add_command( 'mbr-isa', 'MBR_ISA_CLI_Command' );
