<?php
/**
 * Update package integrity verification.
 *
 * The plugin is distributed outside WordPress.org: the manifest is served
 * from GitHub (HarbourBob/mbr-updates) and the package itself from
 * littlewebshack.com. That is a perfectly ordinary arrangement for a
 * privately distributed plugin, but it does mean the update path is a
 * code-delivery path whose integrity rests on two separate hosts, and
 * WordPress performs no verification of its own on a package that did not
 * come from WordPress.org.
 *
 * This class closes that by carrying a SHA-256 of the package in the
 * manifest and checking the downloaded file against it before WordPress is
 * allowed to unpack anything.
 *
 * The manifest gains one optional key:
 *
 *     {
 *       "name":         "MBR Intelligent Site Assistant",
 *       "version":      "0.9.7",
 *       "download_url": "https://littlewebshack.com/.../plugin.zip",
 *       "checksum":     "sha256 hex digest of that exact zip"
 *     }
 *
 * Plugin Update Checker copies every key it finds in the manifest onto the
 * metadata object, so no change to the bundled library is needed to read it.
 *
 * Deliberately backward compatible: a manifest with no checksum updates
 * exactly as it did before. Verification you can forget to publish is
 * verification that will eventually block a legitimate release at an
 * inconvenient moment, so the strict mode is opt-in — define
 * MBR_ISA_REQUIRE_SIGNED_UPDATES as true in wp-config.php once every
 * manifest carries a digest, and an unverifiable package is then refused
 * rather than trusted.
 *
 * What this does and does not buy you. It binds the package to the manifest,
 * so an attacker who can tamper with the package host alone cannot ship
 * code: the digest will not match. It does not defend against an attacker
 * who controls the manifest, since they would simply publish a digest of
 * their own package. Raising that bar means signing the manifest itself,
 * which needs a key distributed with the plugin — worth doing, and a
 * separate piece of work from this one.
 *
 * @package MBR_ISA
 * @since 0.9.7
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Updater {

    /**
     * Option holding the digest most recently advertised by the manifest.
     *
     * @var string
     */
    const CHECKSUM_OPTION = 'mbr_isa_update_checksum';

    /**
     * Wire the verification into the update checker and the upgrader.
     *
     * @param object $update_checker Plugin Update Checker instance.
     * @return void
     */
    public static function bootstrap( $update_checker ) {
        if ( is_object( $update_checker ) && method_exists( $update_checker, 'addFilter' ) ) {
            $update_checker->addFilter(
                'request_info_result',
                [ __CLASS__, 'capture_checksum' ],
                10,
                2
            );
        }

        add_filter( 'upgrader_pre_download', [ __CLASS__, 'verify_package' ], 10, 4 );
    }

    /**
     * Record the digest advertised alongside the latest version.
     *
     * Runs on every update check, which is also the only moment the manifest
     * is in memory — the upgrader that later downloads the package never
     * sees it. Storing what the manifest said is therefore the only way the
     * two can be compared.
     *
     * @param object|null $plugin_info Metadata object from the manifest.
     * @param mixed       $result      Raw HTTP result (unused).
     * @return object|null The metadata object, unmodified.
     */
    public static function capture_checksum( $plugin_info, $result = null ) {
        if ( ! is_object( $plugin_info ) ) {
            return $plugin_info;
        }

        $version  = isset( $plugin_info->version ) ? (string) $plugin_info->version : '';
        $download = isset( $plugin_info->download_url ) ? (string) $plugin_info->download_url : '';

        // __get() on the metadata object returns null for an absent key
        // rather than warning, so an older manifest is simply quiet here.
        $checksum = isset( $plugin_info->checksum ) ? $plugin_info->checksum : null;
        $checksum = is_string( $checksum ) ? strtolower( trim( $checksum ) ) : '';

        if ( '' === $version ) {
            return $plugin_info;
        }

        // Anything that is not a 64-character hex digest is treated as absent
        // rather than as a failure. A typo in the manifest should not be able
        // to wedge updates for every install.
        if ( '' !== $checksum && ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
            $checksum = '';
        }

        update_option(
            self::CHECKSUM_OPTION,
            [
                'version'      => $version,
                'sha256'       => $checksum,
                'download_url' => $download,
            ],
            false
        );

        return $plugin_info;
    }

    /**
     * Download and verify the package before WordPress unpacks it.
     *
     * Returning a non-false value from upgrader_pre_download short-circuits
     * WP_Upgrader::download_package(), so returning a local path hands the
     * upgrader a file we have already checked. Returning a WP_Error stops the
     * update with that message. Returning false leaves WordPress to download
     * it in the ordinary way, which is what happens for every plugin that is
     * not this one.
     *
     * @param bool|string|WP_Error $reply      Short-circuit value.
     * @param string               $package    Package URL.
     * @param WP_Upgrader|null     $upgrader   Upgrader instance.
     * @param array                $hook_extra Context for the operation.
     * @return bool|string|WP_Error
     */
    public static function verify_package( $reply, $package, $upgrader = null, $hook_extra = [] ) {
        // Somebody earlier in the chain has already handled it.
        if ( false !== $reply ) {
            return $reply;
        }

        if ( ! is_string( $package ) || '' === $package ) {
            return $reply;
        }

        if ( ! self::is_our_package( $package, $hook_extra ) ) {
            return $reply;
        }

        $expected = self::expected_checksum( $package );
        $strict   = defined( 'MBR_ISA_REQUIRE_SIGNED_UPDATES' ) && MBR_ISA_REQUIRE_SIGNED_UPDATES;

        if ( '' === $expected ) {
            if ( $strict ) {
                return new WP_Error(
                    'mbr_isa_no_checksum',
                    __( 'MBR Intelligent Site Assistant: the update manifest published no SHA-256 checksum for this package, and this site is configured to require one. The update was not installed.', 'mbr-isa' )
                );
            }

            // No digest published: behave exactly as before.
            return $reply;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $file = download_url( $package );

        if ( is_wp_error( $file ) ) {
            return $file;
        }

        $actual = hash_file( 'sha256', $file );

        if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
            // Delete first, report second. A package that failed verification
            // has no business sitting in the uploads directory whatever the
            // reason for the mismatch.
            if ( file_exists( $file ) ) {
                wp_delete_file( $file );
            }

            return new WP_Error(
                'mbr_isa_checksum_mismatch',
                sprintf(
                    /* translators: 1: expected SHA-256 digest, 2: digest of the downloaded file */
                    __( 'MBR Intelligent Site Assistant: the downloaded update did not match the checksum published in its manifest, so it has been discarded rather than installed. Expected %1$s, got %2$s. If you have just published a release, check that the manifest digest matches the uploaded package.', 'mbr-isa' ),
                    $expected,
                    is_string( $actual ) ? strtolower( $actual ) : 'unreadable'
                )
            );
        }

        // Verified. The upgrader deletes this file when it finishes, because
        // the path it receives differs from the URL it asked for.
        return $file;
    }

    /**
     * Whether this download belongs to this plugin.
     *
     * Two independent signals, because neither is present in every context.
     * A plugin update carries the basename in $hook_extra; an install from
     * the plugin-information modal may not, so the package URL is also
     * compared against the one the manifest advertised.
     *
     * @param string $package    Package URL.
     * @param array  $hook_extra Context for the operation.
     * @return bool
     */
    private static function is_our_package( $package, $hook_extra ) {
        if ( is_array( $hook_extra )
            && isset( $hook_extra['plugin'] )
            && defined( 'MBR_ISA_BASENAME' )
            && $hook_extra['plugin'] === MBR_ISA_BASENAME ) {
            return true;
        }

        $stored = get_option( self::CHECKSUM_OPTION, [] );

        return is_array( $stored )
            && ! empty( $stored['download_url'] )
            && $stored['download_url'] === $package;
    }

    /**
     * The digest the manifest published for this package, if any.
     *
     * The stored download URL is required to match. A digest is a statement
     * about one specific file, so applying a remembered one to a package
     * fetched from somewhere else would be worse than not checking at all —
     * it would report a mismatch for a legitimate file, or, if the URL had
     * been changed by whoever we are defending against, invite them to
     * supply the digest too.
     *
     * @param string $package Package URL being downloaded.
     * @return string Lowercase hex digest, or '' when none applies.
     */
    private static function expected_checksum( $package ) {
        $stored = get_option( self::CHECKSUM_OPTION, [] );

        if ( ! is_array( $stored ) || empty( $stored['sha256'] ) ) {
            return '';
        }

        if ( empty( $stored['download_url'] ) || $stored['download_url'] !== $package ) {
            return '';
        }

        return (string) $stored['sha256'];
    }
}
