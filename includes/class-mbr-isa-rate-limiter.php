<?php
/**
 * Rate limiter — per-IP-hash request throttling on the public endpoints.
 *
 * Uses fixed windows rather than a rolling counter, and prefers an atomic
 * increment where the object cache provides one. See check() for why both
 * of those matter.
 *
 * @package MBR_ISA
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_ISA_Rate_Limiter {

    /**
     * Cache group used when a persistent object cache is available.
     */
    const CACHE_GROUP = 'mbr_isa_rl';

    /**
     * Check whether a request from this IP is allowed, and count it if so.
     *
     * Two changes from the 0.8.1 implementation, both of which were producing
     * wrong answers rather than merely inelegant ones:
     *
     * 1. FIXED WINDOWS. The old version called set_transient() with a fresh
     *    TTL on every request, so the expiry moved forward each time and the
     *    counter only reset after a full window of *silence*. A visitor
     *    running one query every five seconds never exceeds 30/minute, but
     *    would accumulate past 30 and be throttled anyway. The window is now
     *    derived from the clock — floor( time() / $window ) — so it ends when
     *    it ends, regardless of traffic.
     *
     * 2. ATOMIC INCREMENT. read → increment → write is a race: concurrent
     *    requests all read the same value and all write value + 1, so the
     *    effective limit is higher than configured. wp_cache_incr() is atomic
     *    on Memcached and Redis, so it is used whenever a persistent object
     *    cache is present. Sites without one fall back to transients, which
     *    keeps the old race but bounded by a real window — acceptable, since
     *    a site without object caching is unlikely to be handling the
     *    concurrency needed to exploit it.
     *
     * @param string $ip_hash Hash of the requester's IP (see hash_current_ip()).
     * @param int    $limit   Max requests allowed per window.
     * @param int    $window  Window length in seconds.
     * @return bool True if allowed (and counted), false if throttled.
     */
    public function check( $ip_hash, $limit = 30, $window = 60 ) {
        if ( '' === $ip_hash ) {
            return true; // No IP — don't block, just allow.
        }

        $window = max( 1, (int) $window );
        $limit  = max( 1, (int) $limit );

        // Bucket identity includes the window number, so a new window is a
        // new key and expiry does the resetting for us.
        $bucket = (int) floor( time() / $window );
        $key    = 'mbr_isa_rl_' . substr( $ip_hash, 0, 32 ) . '_' . $bucket;

        // Keys live slightly beyond their window so a request arriving at the
        // boundary cannot resurrect a bucket that is about to be read again.
        $ttl = $window + 10;

        if ( wp_using_ext_object_cache() ) {
            $count = wp_cache_incr( $key, 1, self::CACHE_GROUP );

            if ( false === $count ) {
                // Key absent. add() rather than set() so that if another
                // request created it in the meantime we lose the race
                // harmlessly and fall through to incrementing theirs.
                if ( wp_cache_add( $key, 1, self::CACHE_GROUP, $ttl ) ) {
                    return true;
                }
                $count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
                if ( false === $count ) {
                    return true; // Cache misbehaving — fail open, don't lock the site out.
                }
            }

            return $count <= $limit;
        }

        // Transient fallback.
        $current = get_transient( $key );

        if ( false === $current ) {
            set_transient( $key, 1, $ttl );
            return true;
        }

        $current = (int) $current;
        if ( $current >= $limit ) {
            return false;
        }

        set_transient( $key, $current + 1, $ttl );
        return true;
    }

    /**
     * Produce an IP hash suitable for check().
     *
     * REMOTE_ADDR alone is wrong behind a reverse proxy or CDN: every visitor
     * arrives with the proxy's address, so they all share one bucket and a
     * single busy visitor throttles the entire site. Forwarded headers are
     * therefore consulted — but only when the site has explicitly said it is
     * behind a proxy, because those headers are trivially spoofed by the
     * client and trusting them by default would hand every attacker an
     * unlimited supply of fresh buckets.
     *
     * Opt in with either:
     *   define( 'MBR_ISA_TRUST_PROXY', true );   // in wp-config.php
     *   add_filter( 'mbr_isa_trust_proxy', '__return_true' );
     *
     * The address is hashed with wp_salt() so the query log holds no raw IPs.
     *
     * @return string SHA-256 hash, or '' when no address is available.
     */
    public static function hash_current_ip() {
        $ip = self::current_ip();

        if ( '' === $ip ) {
            return '';
        }

        return hash( 'sha256', $ip . wp_salt() );
    }

    /**
     * Resolve the client IP, consulting proxy headers only when trusted.
     *
     * @return string Validated IP address, or '' if none could be determined.
     */
    private static function current_ip() {
        $trust_proxy = ( defined( 'MBR_ISA_TRUST_PROXY' ) && MBR_ISA_TRUST_PROXY );

        /**
         * Filter whether forwarded-for headers may be trusted.
         *
         * @param bool $trust_proxy Current setting.
         */
        $trust_proxy = (bool) apply_filters( 'mbr_isa_trust_proxy', $trust_proxy );

        if ( $trust_proxy ) {
            // Cloudflare's header first — it is set by the edge and cannot be
            // overridden by the client when the site only accepts Cloudflare
            // traffic. Then the first entry of X-Forwarded-For, which is the
            // originating client where the chain is trustworthy.
            $candidates = [];

            if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                $candidates[] = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
            }

            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
                $candidates[] = trim( $parts[0] );
            }

            if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
                $candidates[] = (string) $_SERVER['HTTP_X_REAL_IP'];
            }

            foreach ( $candidates as $candidate ) {
                $valid = filter_var( wp_unslash( $candidate ), FILTER_VALIDATE_IP );
                if ( $valid ) {
                    return $valid;
                }
            }
        }

        if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return '';
        }

        $remote = filter_var( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );

        return $remote ? $remote : '';
    }
}
