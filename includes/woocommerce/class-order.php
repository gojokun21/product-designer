<?php
/**
 * WooCommerce order integration: transfer the design from cart -> order item meta
 * so it survives after checkout (and the cart is cleared).
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Woocommerce;

use ProductDesigner\Core\Design_Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order {

    private Design_Storage $storage;

    public function __construct( Design_Storage $storage ) {
        $this->storage = $storage;
    }

    public function register() : void {
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'transfer_to_order' ], 10, 4 );
        // `woocommerce_order_item_name` e aplicat consistent în admin, pagina
        // de comenzi din My Account, email-uri de comandă, thank-you page.
        // Spre deosebire de `woocommerce_order_item_thumbnail` (care e rar folosit).
        add_filter( 'woocommerce_order_item_name',                 [ $this, 'prepend_design_preview' ], 10, 2 );
        add_filter( 'woocommerce_order_item_thumbnail',            [ $this, 'customer_order_thumbnail' ], 10, 2 );
        add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'format_meta_display' ], 10, 2 );
    }

    /**
     * Called by WC when building order items from cart items.
     *
     * @param \WC_Order_Item_Product $item
     * @param string                 $cart_item_key
     * @param array                  $values         The cart item values.
     * @param \WC_Order              $order
     */
    public function transfer_to_order( $item, $cart_item_key, $values, $order ) : void {
        $design = isset( $values['pd_design'] ) && is_array( $values['pd_design'] ) ? $values['pd_design'] : null;

        // Fallback final: dacă cart item-ul nu are design atașat dar produsul
        // are designer activat, citim din WC Session direct la order creation.
        if ( empty( $design['design_id'] ) ) {
            $product_id = (int) $item->get_product_id();
            if ( $product_id && $this->storage->is_enabled_for_product( $product_id )
                && function_exists( 'WC' ) && WC()->session ) {
                $saved = WC()->session->get( 'pd_design_for_' . $product_id );
                if ( is_array( $saved ) && ! empty( $saved['design_id'] ) ) {
                    $design = $saved;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( "[Product Designer] transfer_to_order: design preluat din session ca fallback pentru product $product_id." );
                    }
                }
            }
        }

        if ( empty( $design['design_id'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[Product Designer] transfer_to_order: cart item "%s" NU are pd_design și session e gol (cart keys: %s) — nu s-a transferat nimic.',
                    $cart_item_key,
                    implode( ',', array_keys( $values ) )
                ) );
            }
            return;
        }

        $item->add_meta_data( Design_Storage::ITEM_META_DESIGN_ID,   $design['design_id'] );
        $item->add_meta_data( Design_Storage::ITEM_META_PREVIEW_URL, $design['preview_url'] ?? '' );
        $item->add_meta_data( Design_Storage::ITEM_META_JSON_URL,    $design['json_url'] ?? '' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[Product Designer] transfer_to_order: OK — design_id {$design['design_id']} transferat pe order item." );
        }
    }

    public function customer_order_thumbnail( string $image, $item ) : string {
        if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
            return $image;
        }
        $preview = (string) $item->get_meta( Design_Storage::ITEM_META_PREVIEW_URL, true );
        if ( ! $preview ) {
            return $image;
        }
        return sprintf(
            '<img src="%s" alt="" style="max-width:64px;height:auto;" />',
            esc_url( $preview )
        );
    }

    /**
     * Prepinde preview-ul design-ului în fața numelui produsului. Se aplică
     * uniform în admin, pagina de comenzi din My Account, emailuri de comandă.
     *
     * @param string                $name
     * @param \WC_Order_Item_Product $item
     */
    public function prepend_design_preview( string $name, $item ) : string {
        if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
            return $name;
        }
        $preview = (string) $item->get_meta( Design_Storage::ITEM_META_PREVIEW_URL, true );
        if ( ! $preview ) {
            return $name;
        }
        $img = sprintf(
            '<img src="%s" alt="" class="pd-order-design-thumb" style="max-width:72px;height:auto;vertical-align:middle;border:1px solid #ddd;padding:2px;background:#fff;margin-right:10px;" />',
            esc_url( $preview )
        );
        return $img . $name;
    }

    /**
     * Hide the raw meta keys from the customer-facing order pages — they are an
     * internal implementation detail. The preview is displayed elsewhere.
     */
    public function format_meta_display( $formatted, $item ) {
        if ( ! is_array( $formatted ) ) {
            return $formatted;
        }
        $hidden = [
            Design_Storage::ITEM_META_DESIGN_ID,
            Design_Storage::ITEM_META_PREVIEW_URL,
            Design_Storage::ITEM_META_JSON_URL,
        ];
        foreach ( $formatted as $id => $meta ) {
            if ( isset( $meta->key ) && in_array( $meta->key, $hidden, true ) ) {
                unset( $formatted[ $id ] );
            }
        }
        return $formatted;
    }
}
