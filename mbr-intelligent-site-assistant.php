<?php
/**
 * Plugin Name:       MBR Intelligent Site Assistant
 * Plugin URI:        https://littlewebshack.com
 * Description:       A self-hosted conversational site search for WordPress. No external APIs, no monthly fees, no data leaves your server.
 * Version:           0.8.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Robert Palmer
 * Author URI:        https://littlewebshack.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mbr-isa
 * Domain Path:       /languages
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Buy Me a Coffee
add_filter( 'plugin_row_meta', function ( $links, $file, $data ) {
    if ( ! function_exists( 'plugin_basename' ) || plugin_basename( __FILE__ ) !== $file ) {
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
define( 'MBR_ISA_VERSION',     '0.8.2' );
define( 'MBR_ISA_FILE',        __FILE__ );
define( 'MBR_ISA_DIR',         plugin_dir_path( __FILE__ ) );
define( 'MBR_ISA_URL',         plugin_dir_url( __FILE__ ) );
define( 'MBR_ISA_BASENAME',    plugin_basename( __FILE__ ) );
define( 'MBR_ISA_DB_VERSION',  '4' );
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

// Load core class files.
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-activator.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-deactivator.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa-tokeniser.php';
require_once MBR_ISA_DIR . 'includes/class-mbr-isa.php';

// Self-hosted update checker.
// Manifest JSON is served from GitHub (HarbourBob/mbr-updates); the package it
// points to is hosted on littlewebshack.com. This is the generic JSON-metadata
// mode of Plugin Update Checker, not the GitHub VCS/releases integration.
//
// The library is an optional, vendored third-party dependency rather than a
// hard requirement — guard its inclusion so a package built or checked out
// without it (e.g. straight from source control) still activates normally.
// Self-update registration simply does not run in that case.
$mbr_isa_puc_bootstrap = MBR_ISA_DIR . 'plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $mbr_isa_puc_bootstrap ) ) {
    require_once $mbr_isa_puc_bootstrap;

    $mbr_isa_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://raw.githubusercontent.com/HarbourBob/mbr-updates/main/mbr-intelligent-site-assistant.json',
        MBR_ISA_FILE,
        'mbr-intelligent-site-assistant'
    );
}
unset( $mbr_isa_puc_bootstrap );

// Activation and deactivation hooks.
register_activation_hook( __FILE__,   [ 'MBR_ISA_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'MBR_ISA_Deactivator', 'deactivate' ] );

// Boot the plugin.
add_action( 'plugins_loaded', function () {
    MBR_ISA::get_instance()->init();
} );