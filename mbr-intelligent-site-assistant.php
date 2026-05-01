<?php
/**
 * Plugin Name:       MBR Intelligent Site Assistant
 * Plugin URI:        https://littlewebshack.com
 * Description:       A self-hosted conversational site search for WordPress. No external APIs, no monthly fees, no data leaves your server.
 * Version:           0.6.1
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
define( 'MBR_ISA_VERSION',     '0.6.1' );
define( 'MBR_ISA_FILE',        __FILE__ );
define( 'MBR_ISA_DIR',         plugin_dir_path( __FILE__ ) );
define( 'MBR_ISA_URL',         plugin_dir_url( __FILE__ ) );
define( 'MBR_ISA_BASENAME',    plugin_basename( __FILE__ ) );
define( 'MBR_ISA_DB_VERSION',  '1' );
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

// Activation and deactivation hooks.
register_activation_hook( __FILE__,   [ 'MBR_ISA_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'MBR_ISA_Deactivator', 'deactivate' ] );

// Boot the plugin.
add_action( 'plugins_loaded', function () {
    MBR_ISA::get_instance()->init();
} );