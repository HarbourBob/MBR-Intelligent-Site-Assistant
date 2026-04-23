<?php
/**
 * Deactivation handler.
 *
 * Unschedules cron events. Does NOT drop tables or delete options —
 * that only happens on full uninstall via uninstall.php, so a user
 * can deactivate and reactivate without losing their index.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Deactivator {

    /**
     * Run on plugin deactivation.
     *
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'mbr_isa_reindex_batch' );
        wp_clear_scheduled_hook( 'mbr_isa_cleanup_query_log' );
    }
}