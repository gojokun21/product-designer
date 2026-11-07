<?php
/**
 * One-time migration of print sizes from schema 1 to schema 2.
 *
 * Schema 1 tied the print size to a variation attribute (`pa_marime-imprimeu`):
 * the variation price included the size, and one size applied to both sides.
 * Schema 2 lets the customer pick a size per side in the editor, priced by the
 * plugin (Design_Storage::print_price()). For every product still on schema 1:
 *
 *  - keeps the variations of the cheapest size (their price, stock and photos
 *    become the base price) and deletes the others;
 *  - drops the print-size attribute from the kept variations and the product;
 *  - writes `_pd_print_sizes` in the new shape, with each size's `price` = its
 *    variation price minus the cheapest one's, and `back_price` = the old
 *    second-side price.
 *
 * It runs in two phases so a request timeout can't leave it half done:
 *
 *  1. Fast, no deletions: read every translation's variations and prices,
 *     write the new meta and queue the variation clean-up (option QUEUE).
 *     Prices are read from the whole translation group before anything is
 *     deleted — Polylang for WooCommerce deletes a variation's translations
 *     along with it.
 *  2. The clean-up, a few seconds per request; the next admin request picks up
 *     where this one stopped. Every step is idempotent.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Print_Sizes_Migration {

    private const LOCK  = 'pd_print_sizes_migrating';
    private const QUEUE = 'pd_print_sizes_cleanup';

    /** Seconds of clean-up per request, well under a typical 30–60 s timeout. */
    private const BUDGET = 12;

    /**
     * @return bool True when every schema-1 product is converted and cleaned up;
     *              false to continue on a later request.
     */
    public static function run() : bool {
        if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Product_Variable' ) ) {
            return false;
        }
        // Two admin requests at once must not work on the same variations.
        if ( get_transient( self::LOCK ) ) {
            return false;
        }
        set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
        $start = microtime( true );
        try {
            self::convert_configs();
            $done = self::process_queue( $start );
        } finally {
            delete_transient( self::LOCK );
        }
        return $done;
    }

    // ------------------------------------------------------------------------
    // Phase 1: prices + new meta + clean-up queue (no deletions).
    // ------------------------------------------------------------------------

    private static function convert_configs() : void {
        global $wpdb;
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            Design_Storage::META_PRINT_SIZES
        ) ) );

        $seen = [];
        foreach ( $ids as $product_id ) {
            if ( isset( $seen[ $product_id ] ) ) {
                continue;
            }
            $group = I18n_Polylang::translation_ids( $product_id );
            foreach ( $group as $id ) {
                $seen[ $id ] = true;
            }
            $legacy = null;
            foreach ( $group as $id ) {
                $raw = get_post_meta( $id, Design_Storage::META_PRINT_SIZES, true );
                if ( is_array( $raw ) && array_key_exists( 'attribute', $raw ) ) {
                    $legacy = $raw;
                    break;
                }
            }
            if ( $legacy ) {
                self::convert_group( $group, $legacy );
            }
        }
    }

    /**
     * @param int[] $group  A product and its translations.
     * @param array $legacy The schema-1 config.
     */
    private static function convert_group( array $group, array $legacy ) : void {
        $attribute  = sanitize_text_field( (string) $legacy['attribute'] );
        $candidates = array_map( 'strval', array_keys( (array) ( $legacy['sizes'] ?? [] ) ) );

        $scans = [];
        foreach ( $group as $id ) {
            $scans[ $id ] = self::scan( $id, $attribute, $candidates );
        }

        // Prices from the translation with the most priced sizes.
        $medians = [];
        foreach ( $scans as $scan ) {
            if ( count( $scan['medians'] ) > count( $medians ) ) {
                $medians = $scan['medians'];
            }
        }
        $base   = $medians ? (string) array_keys( $medians, min( $medians ), true )[0] : '';
        $prices = [];
        foreach ( $medians as $key => $median ) {
            $prices[ $key ] = max( 0.0, round( $median - $medians[ $base ], 2 ) );
        }

        $sizes = [];
        foreach ( (array) ( $legacy['sizes'] ?? [] ) as $slug => $size ) {
            $key = Design_Storage::print_size_key( (string) $slug );
            if ( $key === '' || ! is_array( $size ) ) {
                continue;
            }
            $sizes[ $key ] = [
                'w'          => $size['w'] ?? 0,
                'h'          => $size['h'] ?? 0,
                'anchor'     => $size['anchor'] ?? 'top',
                'price'      => $prices[ $key ] ?? 0,
                'back_price' => $size['second_side_price'] ?? 0,
            ];
        }
        $keys = array_values( array_intersect( Design_Storage::PRINT_SIZE_KEYS, array_keys( $sizes ) ) );
        $new  = Design_Storage::sanitize_print_sizes( [
            'zone_width_cm' => $legacy['zone_width_cm'] ?? 0,
            // The size the base price includes on the front, the largest on the back.
            'default'       => [
                'front' => $base !== '' ? $base : ( $keys[0] ?? '' ),
                'back'  => $keys ? $keys[ count( $keys ) - 1 ] : '',
            ],
            'sizes'         => $sizes,
        ] );

        // Queue first, then the meta: if the request dies in between, the next
        // run still sees schema 1 and queues the (idempotent) clean-up again.
        if ( $base !== '' ) {
            $products = [];
            foreach ( $scans as $id => $scan ) {
                $products[ $id ] = $scan['by_key'];
            }
            $queue   = (array) get_option( self::QUEUE, [] );
            $queue[] = [ 'attribute' => $attribute, 'base' => $base, 'products' => $products ];
            update_option( self::QUEUE, $queue, false );
        }

        foreach ( $group as $id ) {
            if ( ! metadata_exists( 'post', $id, Design_Storage::META_PRINT_SIZES ) ) {
                continue;
            }
            if ( $new['sizes'] ) {
                update_post_meta( $id, Design_Storage::META_PRINT_SIZES, $new );
            } else {
                delete_post_meta( $id, Design_Storage::META_PRINT_SIZES );
            }
        }

        self::log( sprintf( 'converted products %s: base size "%s", prices %s', implode( ',', $group ), $base, wp_json_encode( $prices ) ) );
    }

    /**
     * @param string[] $candidates The schema-1 size slugs (language of the config).
     * @return array{by_key:array<string,int[]>,medians:array<string,float>}
     */
    private static function scan( int $product_id, string $attribute, array $candidates ) : array {
        $out = [ 'by_key' => [], 'medians' => [] ];
        if ( $attribute === '' ) {
            return $out;
        }
        $variation_ids = get_posts( [
            'post_type'        => 'product_variation',
            'post_parent'      => $product_id,
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            // In wp-admin Polylang filters every query by the language picked
            // in the admin bar — the translation's variations would be skipped.
            'lang'             => '',
        ] );
        $prices = [];
        foreach ( array_map( 'intval', $variation_ids ) as $vid ) {
            $slug = (string) get_post_meta( $vid, 'attribute_' . $attribute, true );
            $key  = Design_Storage::print_size_key( $slug );
            if ( $key === '' && $slug !== '' ) {
                // A translation's variation may carry its own language's term slug.
                $key = Design_Storage::print_size_key( I18n_Polylang::match_term_slug( $slug, $attribute, $candidates ) );
            }
            if ( $key === '' ) {
                continue; // "Any" print size, or not one of the sizes: leave it alone.
            }
            $out['by_key'][ $key ][] = $vid;
            $price = get_post_meta( $vid, '_regular_price', true );
            if ( $price !== '' && is_numeric( $price ) ) {
                $prices[ $key ][] = (float) $price;
            }
        }
        foreach ( $prices as $key => $list ) {
            sort( $list );
            $mid                    = intdiv( count( $list ), 2 );
            $out['medians'][ $key ] = count( $list ) % 2 ? $list[ $mid ] : ( $list[ $mid - 1 ] + $list[ $mid ] ) / 2;
        }
        return $out;
    }

    // ------------------------------------------------------------------------
    // Phase 2: variation clean-up, time-boxed and resumable.
    // ------------------------------------------------------------------------

    /** @return bool True when the queue is empty. */
    private static function process_queue( float $start ) : bool {
        $queue = (array) get_option( self::QUEUE, [] );
        while ( $queue ) {
            $task = $queue[0];
            foreach ( array_keys( (array) $task['products'] ) as $product_id ) {
                foreach ( array_keys( (array) $task['products'][ $product_id ] ) as $key ) {
                    while ( ! empty( $task['products'][ $product_id ][ $key ] ) ) {
                        if ( microtime( true ) - $start > self::BUDGET ) {
                            $queue[0] = $task;
                            update_option( self::QUEUE, $queue, false );
                            return false;
                        }
                        $vid = (int) array_shift( $task['products'][ $product_id ][ $key ] );
                        self::clean_variation( $vid, (string) $key === (string) $task['base'], (string) $task['attribute'] );
                    }
                }
                if ( empty( $task['dropped'][ $product_id ] ) ) {
                    self::drop_product_attribute( (int) $product_id, (string) $task['attribute'] );
                    $task['dropped'][ $product_id ] = true;
                    $queue[0]                       = $task;
                    update_option( self::QUEUE, $queue, false );
                }
            }
            array_shift( $queue );
            update_option( self::QUEUE, $queue, false );
            self::log( 'cleaned up products ' . implode( ',', array_keys( (array) $task['products'] ) ) );
        }
        delete_option( self::QUEUE );
        return true;
    }

    /** The base size's variation loses the attribute; the others are deleted. */
    private static function clean_variation( int $vid, bool $is_base, string $attribute ) : void {
        if ( ! $vid || ! get_post( $vid ) ) {
            return; // Already deleted, e.g. along with its translation.
        }
        if ( $is_base ) {
            delete_post_meta( $vid, 'attribute_' . $attribute );
            // The seeded description described the print size.
            update_post_meta( $vid, '_variation_description', '' );
            return;
        }
        $variation = wc_get_product( $vid );
        if ( $variation ) {
            $variation->delete( true );
        } else {
            wp_delete_post( $vid, true );
        }
    }

    private static function drop_product_attribute( int $product_id, string $attribute ) : void {
        $product = wc_get_product( $product_id );
        if ( ! $product instanceof \WC_Product ) {
            return;
        }
        $attributes = $product->get_attributes();
        unset( $attributes[ $attribute ], $attributes[ sanitize_title( $attribute ) ] );
        $product->set_attributes( $attributes );
        $defaults = $product->get_default_attributes();
        unset( $defaults[ $attribute ], $defaults[ sanitize_title( $attribute ) ] );
        $product->set_default_attributes( $defaults );
        $product->save();
        if ( taxonomy_exists( $attribute ) ) {
            wp_set_object_terms( $product_id, [], $attribute );
        }
        if ( $product instanceof \WC_Product_Variable ) {
            \WC_Product_Variable::sync( $product_id );
        }
        wc_delete_product_transients( $product_id );
    }

    private static function log( string $message ) : void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Product Designer] print sizes migration: ' . $message );
        }
    }
}
