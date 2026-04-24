<?php
/**
 * WooCommerce cart integration.
 *
 *  - Captures hidden design fields from the add-to-cart form.
 *  - Attaches them to the cart item so identical products with different designs
 *    are treated as separate line items.
 *  - Renders a preview thumbnail in the cart and checkout views.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Woocommerce;

use ProductDesigner\Core\Design_Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cart {

    private Design_Storage $storage;

    public function __construct( Design_Storage $storage ) {
        $this->storage = $storage;
    }

    public function register() : void {
        add_filter( 'woocommerce_add_cart_item_data',         [ $this, 'capture_design' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data',              [ $this, 'display_in_cart' ], 10, 2 );
        add_filter( 'woocommerce_cart_item_thumbnail',        [ $this, 'replace_thumbnail' ], 10, 3 );
        add_filter( 'woocommerce_add_to_cart_validation',     [ $this, 'validate_cart_add' ], 10, 2 );
    }

    public function validate_cart_add( bool $passed, int $product_id ) : bool {
        if ( ! $this->storage->is_enabled_for_product( $product_id ) ) {
            return $passed;
        }
        // If designer is enabled, customer MUST save a design before adding to cart.
        $design_id = isset( $_POST['pd_design_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pd_design_id'] ) ) : '';

        // Fallback pe WC Session (pentru AJAX add-to-cart / Store API / blocks).
        if ( $design_id === '' && function_exists( 'WC' ) && WC()->session ) {
            $saved = WC()->session->get( 'pd_design_for_' . $product_id );
            if ( is_array( $saved ) && ! empty( $saved['design_id'] ) ) {
                $design_id = (string) $saved['design_id'];
            }
        }

        if ( $design_id === '' ) {
            wc_add_notice( __( 'Te rugăm să personalizezi produsul înainte de a-l adăuga în coș.', 'product-designer' ), 'error' );
            return false;
        }
        return $passed;
    }

    public function capture_design( array $cart_item_data, int $product_id, int $variation_id ) : array {
        if ( ! $this->storage->is_enabled_for_product( $product_id ) ) {
            $this->debug_log( "capture_design: product $product_id nu are designer activat, skip." );
            return $cart_item_data;
        }

        // 1) Calea clasică: hidden inputs din form.cart ajung în $_POST.
        $design_id   = isset( $_POST['pd_design_id'] )   ? sanitize_text_field( wp_unslash( $_POST['pd_design_id'] ) )   : '';
        $preview_url = isset( $_POST['pd_preview_url'] ) ? esc_url_raw( wp_unslash( $_POST['pd_preview_url'] ) )          : '';
        $json_url    = isset( $_POST['pd_json_url'] )    ? esc_url_raw( wp_unslash( $_POST['pd_json_url'] ) )             : '';

        // 2) Fallback: citește din WC Session (completat de REST la save).
        // Robust pentru AJAX add-to-cart, Store API (blocks), și orice alt path
        // care nu trimite hidden inputs în $_POST.
        if ( $design_id === '' && function_exists( 'WC' ) && WC()->session ) {
            $saved = WC()->session->get( 'pd_design_for_' . $product_id );
            if ( is_array( $saved ) && ! empty( $saved['design_id'] ) ) {
                $design_id   = (string) $saved['design_id'];
                $preview_url = (string) ( $saved['preview_url'] ?? '' );
                $json_url    = (string) ( $saved['json_url'] ?? '' );
                $this->debug_log( "capture_design: design_id preluat din WC Session: $design_id" );
            }
        }

        $this->debug_log( sprintf(
            'capture_design: product=%d, design_id="%s", POST keys: %s',
            $product_id,
            $design_id,
            implode( ',', array_filter( array_keys( $_POST ), function ( $k ) { return strpos( (string) $k, 'pd_' ) === 0; } ) )
        ) );

        if ( $design_id === '' ) {
            $this->debug_log( 'capture_design: design_id gol și în POST și în session — add-to-cart fără design.' );
            return $cart_item_data;
        }

        // Confirm the design actually exists on disk before attaching.
        $files = $this->storage->get_design_files( $design_id );
        if ( ! is_readable( $files['json']['path'] ) || ! is_readable( $files['png']['path'] ) ) {
            $this->debug_log( sprintf(
                'capture_design: fișiere LIPSĂ pe disk: json=%s (readable=%s), png=%s (readable=%s)',
                $files['json']['path'],
                is_readable( $files['json']['path'] ) ? 'da' : 'nu',
                $files['png']['path'],
                is_readable( $files['png']['path'] ) ? 'da' : 'nu'
            ) );
            return $cart_item_data;
        }

        $cart_item_data['pd_design'] = [
            'design_id'   => $design_id,
            'preview_url' => $preview_url,
            'json_url'    => $json_url,
            // Unique key forces WC to treat items with different designs as separate rows.
            'unique_key'  => md5( $design_id ),
        ];
        $this->debug_log( "capture_design: OK — design_id $design_id atașat la cart item." );
        return $cart_item_data;
    }

    private function debug_log( string $msg ) : void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Product Designer] ' . $msg );
        }
    }

    public function display_in_cart( array $item_data, array $cart_item ) : array {
        if ( empty( $cart_item['pd_design']['preview_url'] ) ) {
            return $item_data;
        }
        $preview = esc_url( $cart_item['pd_design']['preview_url'] );
        $item_data[] = [
            'key'     => __( 'Design', 'product-designer' ),
            'value'   => wp_kses_post( '<img src="' . $preview . '" alt="" style="max-width:90px;height:auto;vertical-align:middle;border:1px solid #eee;padding:2px;background:#fff;" />' ),
            'display' => '',
        ];
        return $item_data;
    }

    public function replace_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ) : string {
        if ( empty( $cart_item['pd_design']['preview_url'] ) ) {
            return $thumbnail;
        }
        return sprintf(
            '<img src="%s" alt="" class="pd-cart-thumb" style="max-width:64px;height:auto;" />',
            esc_url( $cart_item['pd_design']['preview_url'] )
        );
    }
}
