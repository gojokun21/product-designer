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
use ProductDesigner\Core\I18n_Polylang;
use ProductDesigner\Core\Validator;

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
        add_filter( 'woocommerce_add_to_cart_validation',     [ $this, 'validate_cart_add' ], 10, 5 );
        // The print-size extra goes on the line's product object. Both hooks
        // get a freshly loaded product (on add, and on every cart load from the
        // session), so the price is raised exactly once per request.
        add_filter( 'woocommerce_add_cart_item',              [ $this, 'apply_extra_price' ], 20, 1 );
        add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'apply_extra_price' ], 20, 1 );
    }

    /**
     * @param bool  $passed
     * @param int   $product_id
     * @param int   $quantity
     * @param int   $variation_id
     * @param array $variations   `attribute_*` => slug for variable products.
     */
    public function validate_cart_add( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = [] ) : bool {
        $product_id = (int) $product_id;
        if ( ! $passed || ! $this->storage->is_enabled_for_product( $product_id ) ) {
            return (bool) $passed;
        }
        // If designer is enabled, customer MUST save a design before adding to cart.
        $design = $this->submitted_design( $product_id );
        if ( $design['design_id'] === '' ) {
            wc_add_notice( __( 'Te rugăm să personalizezi produsul înainte de a-l adăuga în coș.', 'product-designer' ), 'error' );
            return false;
        }

        // The price follows the print size of each printed side, read from the
        // saved JSON. A size the admin has since removed can't be priced.
        if ( $this->storage->get_print_sizes( $product_id )['sizes'] ) {
            $json  = $this->storage->get_design_json( $design['design_id'] );
            $sizes = is_array( $json ) ? Design_Storage::design_side_sizes( $json ) : [];
            $price = $sizes ? $this->storage->print_price( $product_id, $sizes ) : null;
            if ( ! $price || in_array( '', array_column( $price['sides'], 'size' ), true ) ) {
                wc_add_notice( __( 'Mărimea imprimeului din design nu mai este disponibilă. Deschide editorul, alege mărimea și salvează designul din nou.', 'product-designer' ), 'error' );
                return false;
            }
        }
        return true;
    }

    /**
     * The design that goes with an add-to-cart: the hidden `pd_*` fields from
     * the form, else the last one saved in the WC session (REST save) for AJAX /
     * Store API add-to-cart paths that don't post them.
     *
     * @return array{design_id:string,preview_url:string,preview_back_url:string,json_url:string,mockup_color:string}
     */
    private function submitted_design( int $product_id ) : array {
        $design_id = isset( $_POST['pd_design_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pd_design_id'] ) ) : '';
        if ( $design_id !== '' ) {
            return [
                'design_id'        => $design_id,
                'preview_url'      => isset( $_POST['pd_preview_url'] )      ? esc_url_raw( wp_unslash( $_POST['pd_preview_url'] ) )              : '',
                'preview_back_url' => isset( $_POST['pd_preview_back_url'] ) ? esc_url_raw( wp_unslash( $_POST['pd_preview_back_url'] ) )         : '',
                'json_url'         => isset( $_POST['pd_json_url'] )         ? esc_url_raw( wp_unslash( $_POST['pd_json_url'] ) )                 : '',
                'mockup_color'     => isset( $_POST['pd_mockup_color'] )     ? Validator::sanitize_hex( wp_unslash( $_POST['pd_mockup_color'] ) ) : '',
            ];
        }

        $saved = $this->session_design( $product_id );
        if ( ! empty( $saved['design_id'] ) ) {
            $this->debug_log( 'submitted_design: design_id preluat din WC Session: ' . $saved['design_id'] );
        }
        return [
            'design_id'        => (string) ( $saved['design_id'] ?? '' ),
            'preview_url'      => (string) ( $saved['preview_url'] ?? '' ),
            'preview_back_url' => (string) ( $saved['preview_back_url'] ?? '' ),
            'json_url'         => (string) ( $saved['json_url'] ?? '' ),
            'mockup_color'     => Validator::sanitize_hex( $saved['mockup_color'] ?? '' ),
        ];
    }

    public function apply_extra_price( $cart_item ) {
        $extra = (float) ( $cart_item['pd_design']['extra_price'] ?? 0 );
        if ( $extra > 0 && isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) {
            $cart_item['data']->set_price( (float) $cart_item['data']->get_price( 'edit' ) + $extra );
        }
        return $cart_item;
    }

    public function capture_design( array $cart_item_data, int $product_id, int $variation_id ) : array {
        if ( ! $this->storage->is_enabled_for_product( $product_id ) ) {
            $this->debug_log( "capture_design: product $product_id nu are designer activat, skip." );
            return $cart_item_data;
        }

        $design       = $this->submitted_design( $product_id );
        $design_id    = $design['design_id'];
        $mockup_color = $design['mockup_color'];

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
        // The PNG path returned by get_design_files() prefers front-suffixed
        // (`pd-{id}-front.png`) and falls back to legacy (`pd-{id}.png`), so
        // a back-only design (no front) would fail this check. We accept it as
        // long as JSON exists AND at least one preview file is readable.
        $files = $this->storage->get_design_files( $design_id );
        $front_png_path = $files['png']['path'] ?? '';
        $back_paths     = $this->storage->get_design_files_for_side( $design_id, 'back' );
        $back_png_path  = $back_paths['png']['path'] ?? '';

        $has_any_png = ( $front_png_path && is_readable( $front_png_path ) )
                    || ( $back_png_path  && is_readable( $back_png_path ) );

        if ( ! is_readable( $files['json']['path'] ) || ! $has_any_png ) {
            $this->debug_log( sprintf(
                'capture_design: fișiere LIPSĂ pe disk: json=%s (readable=%s), front_png=%s (readable=%s), back_png=%s (readable=%s)',
                $files['json']['path'],
                is_readable( $files['json']['path'] ) ? 'da' : 'nu',
                $front_png_path,
                $front_png_path && is_readable( $front_png_path ) ? 'da' : 'nu',
                $back_png_path,
                $back_png_path && is_readable( $back_png_path ) ? 'da' : 'nu'
            ) );
            return $cart_item_data;
        }

        // Everything that decides the price comes from the server's own files,
        // not from the posted fields: the printed sides and their print sizes
        // from the JSON, the preview URLs from disk. Posting an empty back URL
        // no longer skips the back's price.
        $json  = $this->storage->get_design_json( $design_id );
        $urls  = $this->storage->preview_urls( $design_id );
        $price = $this->storage->print_price( $product_id, is_array( $json ) ? Design_Storage::design_side_sizes( $json ) : [] );

        $cart_item_data['pd_design'] = [
            'design_id'        => $design_id,
            'preview_url'      => $urls['front'],
            'preview_back_url' => $urls['back'],
            'json_url'         => (string) $files['json']['url'],
            'mockup_color'     => $mockup_color,
            // side => {size, label, w, h, amount}; see Design_Storage::print_price().
            'print'            => $price['sides'],
            'extra_price'      => $price['extra'],
            // Unique key forces WC to treat items with different designs as separate rows.
            'unique_key'       => md5( $design_id ),
        ];
        $this->debug_log( sprintf(
            'capture_design: OK — design_id %s atașat la cart item (front=%s, back=%s, extra=%s).',
            $design_id,
            $urls['front'] !== '' ? 'da' : 'nu',
            $urls['back'] !== '' ? 'da' : 'nu',
            $price['extra']
        ) );
        return $cart_item_data;
    }

    /**
     * Designul salvat in WC Session pentru produs sau pentru oricare dintre
     * traducerile lui.
     *
     * Polylang for WooCommerce traduce ID-ul produsului la add-to-cart
     * (filtrul woocommerce_add_to_cart_product_id), deci ID-ul care ajunge aici
     * poate sa nu fie cel sub care REST-ul a salvat designul in sesiune.
     *
     * @return array Randul din sesiune, sau array gol.
     */
    private function session_design( int $product_id ) : array {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return [];
        }
        foreach ( I18n_Polylang::translation_ids( $product_id ) as $id ) {
            $saved = WC()->session->get( 'pd_design_for_' . $id );
            if ( is_array( $saved ) && ! empty( $saved['design_id'] ) ) {
                return $saved;
            }
        }
        return [];
    }

    private function debug_log( string $msg ) : void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Product Designer] ' . $msg );
        }
    }

    /**
     * „Față: Mic 10 × 10 cm · Spate: Mare 28 × 40 cm (+100 MDL)" — where the
     * design is printed, how big, and what the print sizes added to the price.
     * Cart lines added before per-side sizes (no `print` key) keep the old
     * „Față + spate (+75 MDL)" wording.
     */
    public static function print_sides_label( array $design ) : string {
        if ( isset( $design['print'] ) && is_array( $design['print'] ) ) {
            return Design_Storage::print_summary( [ 'sides' => $design['print'], 'extra' => $design['extra_price'] ?? 0 ] );
        }
        $sides = [];
        if ( ! empty( $design['preview_url'] ) ) {
            $sides[] = __( 'Față', 'product-designer' );
        }
        if ( ! empty( $design['preview_back_url'] ) ) {
            $sides[] = $sides ? mb_strtolower( __( 'Spate', 'product-designer' ) ) : __( 'Spate', 'product-designer' );
        }
        $label = implode( ' + ', $sides );
        $extra = (float) ( $design['extra_price'] ?? 0 );
        if ( $label !== '' && $extra > 0 ) {
            $label .= ' (+' . Design_Storage::price_text( $extra ) . ')';
        }
        return $label;
    }

    public function display_in_cart( array $item_data, array $cart_item ) : array {
        $sides = isset( $cart_item['pd_design'] ) && is_array( $cart_item['pd_design'] ) ? self::print_sides_label( $cart_item['pd_design'] ) : '';
        if ( $sides !== '' ) {
            $item_data[] = [
                'key'   => __( 'Imprimeu', 'product-designer' ),
                'value' => esc_html( $sides ),
            ];
        }

        // Preview-ul design-ului NU se mai listează ca rând de meta („Design")
        // — thumbnail-ul produsului din coș e deja înlocuit cu preview-ul
        // (replace_thumbnail), deci rândul ar dubla aceeași imagine.
        $color = Validator::sanitize_hex( $cart_item['pd_design']['mockup_color'] ?? '' );
        if ( $color !== '' ) {
            // „Alb" în loc de #ffffff când hex-ul corespunde termenului de
            // variație; culorile custom (fără termen) rămân afișate ca hex.
            $name  = $this->storage->color_label_for_variation( $color, (array) ( $cart_item['variation'] ?? [] ) );
            $label = $name !== '' ? esc_html( $name ) : '<code>' . esc_html( $color ) . '</code>';
            // Fără `display`/`width`/`height` — wp_kses_post (aplicat de WC la
            // afișare) elimină `display`, iar span-ul inline ar colapsa într-o
            // liniuță. Padding-ul dă lățimea, iar font-size:0 anulează înălțimea
            // moștenită din font (altfel cercul iese pastilă ovală).
            $swatch = sprintf(
                '<span style="padding:6px;font-size:0;line-height:0;background:%s;border:1px solid #ccc;border-radius:50%%;margin-right:6px;vertical-align:middle;">&nbsp;</span>%s',
                esc_attr( $color ),
                $label
            );
            $item_data[] = [
                'key'     => __( 'Culoare produs', 'product-designer' ),
                'value'   => wp_kses_post( $swatch ),
                'display' => '',
            ];
        }
        return $item_data;
    }

    public function replace_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ) : string {
        $front = (string) ( $cart_item['pd_design']['preview_url']      ?? '' );
        $back  = (string) ( $cart_item['pd_design']['preview_back_url'] ?? '' );
        // Cart thumbnail rămâne single — preferă front, fallback la back.
        $url = $front !== '' ? $front : $back;
        if ( $url === '' ) {
            return $thumbnail;
        }
        // Fără sizing inline — imaginea umple containerul temei
        // (.cart_item_image img: 100%/100%, object-fit cover).
        return sprintf(
            '<img src="%s" alt="" class="pd-cart-thumb" />',
            esc_url( $url )
        );
    }
}
