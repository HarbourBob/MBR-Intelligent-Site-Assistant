<?php
/**
 * Plugin Name:       MBR Intelligent Site Assistant
 * Plugin URI:        https://littlewebshack.com
 * Description:       A self-hosted conversational site search for WordPress. No external APIs are used for search, and visitor queries never leave your server.
 * Version:           0.9.21
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Robert Palmer
 * Author URI:        https://littlewebshack.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mbr-isa
 * Domain Path:       /languages
 * Update URI:        https://littlewebshack.com/mbr-intelligent-site-assistant/
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Buy Me a Coffee
add_filter( 'plugin_row_meta', function ( $links, $file, $data ) {
    if ( ! function_exists( 'plugin_basename' ) || $file !== plugin_basename( __FILE__ ) ) {
        return $links;
    }

    $url = 'https://buymeacoffee.com/robertpalmer/';
    $links[] = sprintf(
        '<a href="%s" target="_blank" rel="noopener nofollow" aria-label="%s">☕ %s</a>',
        esc_url( $url ),
		// translators: %s: The name of the plugin author.
        esc_attr( sprintf( __( 'Buy %s a coffee', 'mbr-isa' ), isset( $data['AuthorName'] ) ? $data['AuthorName'] : __( 'the author', 'mbr-isa' ) ) ),
        esc_html__( 'Buy me a coffee', 'mbr-isa' )
    );

    return $links;
}, 10, 3 );

// Plugin constants.
define( 'MBR_ISA_VERSION',     '0.9.21' );
define( 'MBR_ISA_FILE',        __FILE__ );
define( 'MBR_ISA_DIR',         plugin_dir_path( __FILE__ ) );
define( 'MBR_ISA_URL',         plugin_dir_url( __FILE__ ) );
define( 'MBR_ISA_BASENAME',    plugin_basename( __FILE__ ) );
define( 'MBR_ISA_DB_VERSION',  '6' );
define( 'MBR_ISA_MIN_PHP',     '7.4' );

// PHP version guard — belt and braces alongside the header.
if ( version_compare( PHP_VERSION, MBR_ISA_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html( sprintf(
            /* translators: 1: required PHP version, 2: current PHP version */
            __( 'MBR Intelligent Site Assistant requires PHP %1$s or higher. You are running PHP %2$s. The plugin has been disabled.', 'mbr-isa' ),
            MBR_ISA_MIN_PHP,
            PHP_VERSION
        ) );
        echo '</p></div>';
    } );
    return;
}

/*
 * mbstring is a hard requirement, so it is declared as one.
 *
 * The indexer, the tokeniser and the responder all call mb_* unconditionally
 * on the index-write and search paths, so without the extension the plugin
 * fatals on the first indexed post or the first visitor query. Until 0.9.19 a
 * handful of scattered function_exists() guards promised a graceful
 * degradation the rest of the code could not deliver — and cost a lookup per
 * token of every document to do it. An honest runtime check is the only place
 * this can be stated, since a plugin header cannot express an extension
 * requirement.
 *
 * @since 0.9.19
 */
if ( ! extension_loaded( 'mbstring' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'MBR Intelligent Site Assistant requires the PHP mbstring extension, which is not enabled on this server. The plugin has been disabled. Most hosts can enable it on request.',
            'mbr-isa'
        );
        echo '</p></div>';
    } );
    return;
}

// Load core class files.
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-activator.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-deactivator.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-tokeniser.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-updater.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa.php';

// Self-hosted update checker.
// Manifest JSON is served from GitHub (HarbourBob/mbr-updates); the package it
// points to is hosted on littlewebshack.com. This is the generic JSON-metadata
// mode of Plugin Update Checker, not the GitHub VCS/releases integration.
require_once MBR_ISA_DIR . 'plugin-update-checker/plugin-update-checker.php';

$mbr_isa_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://raw.githubusercontent.com/HarbourBob/mbr-updates/main/mbr-intelligent-site-assistant.json',
    MBR_ISA_FILE,
    'mbr-intelligent-site-assistant'
);

// Verify the package against the SHA-256 published in the manifest before
// WordPress unpacks it. Backward compatible: a manifest with no "checksum"
// key updates exactly as it always has. See class-mbr-isa-updater.php.
MBR_ISA_Updater::bootstrap( $mbr_isa_update_checker );

/*
 * Strip the fingerprinting arguments from the update check.
 *
 * Plugin Update Checker appends the site's PHP version, locale and installed
 * plugin version to every request by default. The manifest is a static JSON
 * file on raw.githubusercontent.com, which ignores query arguments entirely —
 * so twice a day these tell GitHub the exact PHP version and locale of a site
 * running this plugin, alongside its server IP, and buy nothing in return.
 */
if ( method_exists( $mbr_isa_update_checker, 'addQueryArgFilter' ) ) {
    $mbr_isa_update_checker->addQueryArgFilter( function ( $args ) {
        unset( $args['php'], $args['locale'], $args['installed_version'] );
        return $args;
    } );
}

// Activation and deactivation hooks.
register_activation_hook( __FILE__,   [ 'MBR_ISA_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'MBR_ISA_Deactivator', 'deactivate' ] );

// Boot the plugin.
add_action( 'plugins_loaded', function () {
    MBR_ISA::get_instance()->init();
} );