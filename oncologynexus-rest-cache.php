<?php
/**
 * Plugin Name: OncologyNexus REST API Cache
 * Description: Caches public WordPress REST API GET responses in transients so build-time fetches (the Next.js static export) answer from the cache instead of hammering the database. Requests that send `Cache-Control: no-cache` are always forwarded fresh.
 * Author: Pedro Molina
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * The default TTL is 60 seconds (tweak with `ONX_REST_CACHE_TTL` constant or
 * the `onx_rest_cache_ttl` filter). Private/draft preview routes and any
 * authenticated request are never cached.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OncologyNexus_REST_Cache {

    const TTL_DEFAULT = 60 * 15;

    public function __construct() {
        add_filter( 'rest_pre_dispatch', array( $this, 'maybe_serve_cache' ), 10, 3 );
        add_filter( 'rest_post_dispatch', array( $this, 'maybe_store_cache' ), 10, 3 );
    }

    /**
     * Effective cache lifetime (seconds).
     */
    public function ttl() {
        $ttl = defined( 'ONX_REST_CACHE_TTL' ) ? (int) ONX_REST_CACHE_TTL : self::TTL_DEFAULT;
        $ttl = apply_filters( 'onx_rest_cache_ttl', $ttl );
        return max( 1, (int) $ttl );
    }

    /**
     * Serve a cached response before WordPress boots the route callbacks.
     */
    public function maybe_serve_cache( $result, $server, $request ) {
        if ( ! $this->is_cacheable_request( $request ) ) {
            return $result;
        }

        $data = get_transient( $this->cache_key( $request ) );
        if ( $data === false || ! is_array( $data ) || ! isset( $data['status'] ) ) {
            return $result;
        }

        $response = new WP_REST_Response( isset( $data['data'] ) ? $data['data'] : array(), (int) $data['status'] );

        if ( ! empty( $data['headers'] ) && is_array( $data['headers'] ) ) {
            foreach ( $data['headers'] as $name => $value ) {
                if ( $name && $value ) {
                    $response->header( (string) $name, (string) $value );
                }
            }
        }

        $response->header( 'X-Onx-From-Cache', '1' );
        return $response;
    }

    /**
     * Store a successful response for later GET requests.
     */
    public function maybe_store_cache( $response, $server, $request ) {
        if ( ! $this->is_cacheable_request( $request ) ) {
            return $response;
        }
        if ( ! $response instanceof WP_REST_Response ) {
            return $response;
        }

        $status = (int) $response->get_status();
        if ( $status < 200 || $status >= 300 ) {
            return $response;
        }

        $payload = array(
            'status'  => $status,
            'headers' => $response->get_headers(),
            'data'    => $response->get_data(),
            'time'    => time(),
        );

        set_transient( $this->cache_key( $request ), $payload, $this->ttl() );
        return $response;
    }

    /**
     * Whether a request is safe to cache: public GET on a consumed namespace.
     */
    protected function is_cacheable_request( $request ) {
        if ( strtoupper( (string) $request->get_method() ) !== 'GET' ) {
            return false;
        }

        // The headless build sends Cache-Control: no-cache when it needs a
        // fresh payload (e.g. content lists on incremental deploys).
        $cache_control = $request->get_header( 'cache-control' );
        if ( $cache_control && false !== stripos( $cache_control, 'no-cache' ) ) {
            return false;
        }

        $route = (string) $request->get_route();
        if ( 0 !== strpos( $route, '/wp/v2/' ) && 0 !== strpos( $route, '/oncologynexus/v1/' ) ) {
            return false;
        }

        // Never cache token-gated draft/preview content.
        if ( 0 === strpos( $route, '/oncologynexus/v1/preview-post' ) ) {
            return false;
        }

        // Term blocks resolve editable templates and must reflect saves immediately.
        if ( $route === '/oncologynexus/v1/term-blocks' ) {
            return false;
        }

        // Skip authenticated requests.
        if ( $request->get_header( 'x_wp_nonce' ) || $request->get_header( 'authorization' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Stable, namespaced cache key for a request (route + query params).
     */
    protected function cache_key( $request ) {
        $params = (array) $request->get_params();
        ksort( $params );
        return 'onx_rc_' . md5( (string) $request->get_route() . '?' . http_build_query( $params ) );
    }
}

new OncologyNexus_REST_Cache();
