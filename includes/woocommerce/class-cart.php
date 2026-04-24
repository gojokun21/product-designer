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
        if ( $design_id === '' ) {
            wc_add_notice( __( 'Te rugăm să personalizezi produsul înainte de a-l adăuga în coș.', 'product-designer' ), 'error' );
            return false;
        }
        return $passed;
    }

    public function capture_design( array $cart_item_data, int $product_id, int $variation_id ) : array {
        if ( ! $this->storage->is_enabled_for_product( $product_id ) ) {
            return $cart_item_data;
        }

        $design_id   = isset( $_POST['pd_design_id'] )   ? sanitize_text_field( wp_unslash( $_POST['pd_design_id'] ) )   : '';
        $preview_url = isset( $_POST['pd_preview_url'] ) ? esc_url_raw( wp_unslash( $_POST['pd_preview_url'] ) )          : '';
        $json_url    = isset( $_POST['pd_json_url'] )    ? esc_url_raw( wp_unslash( $_POST['pd_json_url'] ) )             : '';

        if ( $design_id === '' ) {
            return $cart_item_data;
        }

        // Confirm the design actually exists on disk before attaching.
        $files = $this->storage->get_design_files( $design_id );
        if ( ! is_readable( $files['json']['path'] ) || ! is_readable( $files['png']['path'] ) ) {
            return $cart_item_data;
        }

        $cart_item_data['pd_design'] = [
            'design_id'   => $design_id,
            'preview_url' => $preview_url,
            'json_url'    => $json_url,
            // Unique key forces WC to treat items with different designs as separate rows.
            'unique_key'  => md5( $design_id ),
        ];
        return $cart_item_data;
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
