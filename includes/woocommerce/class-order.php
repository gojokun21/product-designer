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
        if ( empty( $values['pd_design']['design_id'] ) ) {
            return;
        }
        $design = $values['pd_design'];
        $item->add_meta_data( Design_Storage::ITEM_META_DESIGN_ID,   $design['design_id'] );
        $item->add_meta_data( Design_Storage::ITEM_META_PREVIEW_URL, $design['preview_url'] ?? '' );
        $item->add_meta_data( Design_Storage::ITEM_META_JSON_URL,    $design['json_url'] ?? '' );
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
