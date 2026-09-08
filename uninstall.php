<?php
/**
 * Uninstall routine for MBR Intelligent Site Assistant.
 *
 * Runs only when the user deletes the plugin via the WordPress admin,
 * not on deactivation. Cleans up all data the plugin created.
 *
 * On multisite the cleanup runs per site. Everything this plugin creates is
 * per-site — four tables on the site's own prefix, ten options in its own
 * options table, three cron events on its own schedule — so deleting a
 * network-activated copy while looking only at $wpdb->prefix would leave
 * every other site in the network fully populated with orphaned data.
 *
 * @package MBR_ISA
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

/**
 * Remove every trace of the plugin from the current site.
 *
 * Called once on a single-site install, and once per site inside a
 * switch_to_blog() loop on multisite.
 *
 * @return void
 */
function mbr_isa_uninstall_current_site() {
    global $wpdb;

    // Respect the "keep data on uninstall" option if the user has set it.
    // Checked per site: one site in a network may want its index kept even
    // where another does not.
    if ( get_option( 'mbr_isa_keep_data_on_uninstall', false ) ) {
        return;
    }

    // Drop custom tables.
    $tables = [
        $wpdb->prefix . 'mbrisa_terms',
        $wpdb->prefix . 'mbrisa_documents',
        $wpdb->prefix . 'mbrisa_postings',
        $wpdb->prefix . 'mbrisa_queries',
    ];

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
    }

    // Delete all plugin options.
    $options = [
        'mbr_isa_version',
        'mbr_isa_db_version',
        'mbr_isa_settings',
        'mbr_isa_intents',
        'mbr_isa_synonyms',
        'mbr_isa_index_status',
        'mbr_isa_corpus_stats',
        'mbr_isa_purge_cursor',
        'mbr_isa_update_checksum',
        'mbr_isa_keep_data_on_uninstall',
    ];

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Clear scheduled cron events.
    wp_clear_scheduled_hook( 'mbr_isa_reindex_batch' );
    wp_clear_scheduled_hook( 'mbr_isa_cleanup_query_log' );
    wp_clear_scheduled_hook( 'mbr_isa_purge_stale_index' );

    // Drop any transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_mbr\_isa\_%'
            OR option_name LIKE '\_transient\_timeout\_mbr\_isa\_%'"
    ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->options.
}

if ( is_multisite() ) {
    // number => 0 means no limit; a large network is still bounded by the
    // number of sites, and this runs once, at deletion.
    $mbr_isa_site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );

    foreach ( $mbr_isa_site_ids as $mbr_isa_site_id ) {
        switch_to_blog( (int) $mbr_isa_site_id );
        mbr_isa_uninstall_current_site();
        restore_current_blog();
    }
} else {
    mbr_isa_uninstall_current_site();
}
