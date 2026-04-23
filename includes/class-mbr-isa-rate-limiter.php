<?php
/**
 * Rate limiter — simple per-IP-hash request throttling.
 *
 * Stores a sliding count in a transient keyed by IP hash. Returns
 * true if the request is allowed, false if the limit has been hit.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Rate_Limiter {

    /**
     * Check whether a request from this IP is allowed, and count it if so.
     *
     * @param string $ip_hash SHA-256 hash of the requester's IP + a salt.
     * @param int    $limit   Max requests allowed per window.
     * @param int    $window  Window length in seconds.
     * @return bool True if allowed (and counted), false if throttled.
     */
    public function check( $ip_hash, $limit = 30, $window = 60 ) {
        if ( '' === $ip_hash ) {
            return true; // No IP — don't block, just allow.
        }

        $key     = 'mbr_isa_rl_' . substr( $ip_hash, 0, 32 );
        $current = get_transient( $key );

        if ( false === $current ) {
            set_transient( $key, 1, $window );
            return true;
        }

        $current = (int) $current;
        if ( $current >= $limit ) {
            return false;
        }

        set_transient( $key, $current + 1, $window );
        return true;
    }

    /**
     * Helper to produce an IP hash suitable for the check() method.
     *
     * @return string
     */
    public static function hash_current_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ( '' === $ip ) {
            return '';
        }
        return hash( 'sha256', $ip . wp_salt() );
    }
}