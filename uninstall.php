<?php
/**
 * Uninstall routine for MBR Intelligent Site Assistant.
 *
 * Runs only when the user deletes the plugin via the WordPress admin,
 * not on deactivation. Cleans up all data the plugin created.
 *
 * @package MBR_ISA
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Respect the "keep data on uninstall" option if the user has set it.
$keep_data = get_option( 'mbr_isa_keep_data_on_uninstall', false );

if ( $keep_data ) {
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
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete all plugin options.
$options = [
    'mbr_isa_version',
    'mbr_isa_db_version',
    'mbr_isa_settings',
    'mbr_isa_intents',
    'mbr_isa_synonyms',
    'mbr_isa_index_status',
    'mbr_isa_keep_data_on_uninstall',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'mbr_isa_reindex_batch' );
wp_clear_scheduled_hook( 'mbr_isa_cleanup_query_log' );

// Drop any transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_mbr\_isa\_%'
        OR option_name LIKE '\_transient\_timeout\_mbr\_isa\_%'"
);