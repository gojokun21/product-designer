<?php
/**
 * Vite asset loader.
 *
 * Reads the Vite build manifest (assets/dist/.vite/manifest.json) and enqueues
 * the hashed JS entry + its CSS. In dev mode (define PD_VITE_DEV true) it loads
 * the Vite dev server with HMR instead.
 *
 * Replaces the old hand-written wp_enqueue_script/style + filemtime cache-busting.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Vite {

    private const DIST = 'assets/dist';
    private const DEV_SERVER = 'http://localhost:5173';

    /** Parsed manifest, cached per request. */
    private static ?array $manifest = null;

    /** Handles we tag as ES modules. */
    private static array $module_handles = [];

    public function register() : void {
        add_filter( 'script_loader_tag', [ $this, 'module_type' ], 10, 2 );
    }

    /** Add type="module" to our Vite script handles. */
    public function module_type( string $tag, string $handle ) : string {
        if ( ! in_array( $handle, self::$module_handles, true ) ) {
            return $tag;
        }
        if ( false !== strpos( $tag, 'type="module"' ) ) {
            return $tag;
        }
        // Strip any existing type attribute (WP emits type='text/javascript'
        // unless the theme declares html5 'script' support) so we don't end up
        // with two `type` attributes — the browser would pick the first one and
        // the ES module would fail to parse.
        $tag = preg_replace( '/\stype=([\'"])[^\'"]*\1/', '', $tag );
        $tag = preg_replace( '/^<script\s/', '<script type="module" ', $tag );
        return $tag;
    }

    private function is_dev() : bool {
        return defined( 'PD_VITE_DEV' ) && PD_VITE_DEV;
    }

    private function manifest() : array {
        if ( self::$manifest !== null ) {
            return self::$manifest;
        }
        $path = PD_PLUGIN_DIR . self::DIST . '/.vite/manifest.json';
        if ( is_readable( $path ) ) {
            $decoded = json_decode( (string) file_get_contents( $path ), true );
            self::$manifest = is_array( $decoded ) ? $decoded : [];
        } else {
            self::$manifest = [];
        }
        return self::$manifest;
    }

    /**
     * Enqueue a build entry.
     *
     * @param string $entry  Manifest key, e.g. 'src/main-product.js'.
     * @param string $handle Unique handle suffix, e.g. 'product'.
     * @param array  $deps   Script dependencies (e.g. ['jquery']).
     */
    public function enqueue_entry( string $entry, string $handle, array $deps = [] ) : void {
        $script_handle = 'pd-vite-' . $handle;

        if ( $this->is_dev() ) {
            // Vite dev server (HMR). The client must load first as a module.
            wp_enqueue_script( 'pd-vite-client', self::DEV_SERVER . '/@vite/client', [], null, true );
            self::$module_handles[] = 'pd-vite-client';
            wp_enqueue_script( $script_handle, self::DEV_SERVER . '/' . $entry, array_merge( $deps, [ 'pd-vite-client' ] ), null, true );
            self::$module_handles[] = $script_handle;
            return;
        }

        $manifest = $this->manifest();
        if ( empty( $manifest[ $entry ] ) || empty( $manifest[ $entry ]['file'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Product Designer] Vite manifest missing entry: ' . $entry . ' — run `npm run build`.' );
            }
            return;
        }

        $base = PD_PLUGIN_URL . self::DIST . '/';
        $node = $manifest[ $entry ];

        wp_enqueue_script( $script_handle, $base . $node['file'], $deps, null, true );
        self::$module_handles[] = $script_handle;

        // Entry CSS + CSS from any imported (shared) chunks.
        $css_files = $this->collect_css( $entry, $manifest, [] );
        $i = 0;
        foreach ( array_unique( $css_files ) as $css ) {
            wp_enqueue_style( $script_handle . '-css-' . $i, $base . $css, [], null );
            $i++;
        }
    }

    /**
     * Recursively gather CSS for an entry and its imported chunks.
     *
     * @param string $key      Manifest key.
     * @param array  $manifest Full manifest.
     * @param array  $seen     Visited keys (cycle guard).
     * @return string[]
     */
    private function collect_css( string $key, array $manifest, array $seen ) : array {
        if ( empty( $manifest[ $key ] ) || in_array( $key, $seen, true ) ) {
            return [];
        }
        $seen[] = $key;
        $node = $manifest[ $key ];
        $css  = isset( $node['css'] ) ? (array) $node['css'] : [];
        if ( ! empty( $node['imports'] ) ) {
            foreach ( (array) $node['imports'] as $import ) {
                $css = array_merge( $css, $this->collect_css( $import, $manifest, $seen ) );
            }
        }
        return $css;
    }
}
