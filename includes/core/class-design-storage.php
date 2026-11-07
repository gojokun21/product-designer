<?php
/**
 * Persists and retrieves designs. Designs are stored on disk (JSON + PNG preview);
 * cart/order item meta holds only the design_id + public preview URL, keeping rows small.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Design_Storage {

    public const META_ENABLED            = '_pd_enabled';
    // Front mockup. Keeps the original meta key for backwards compatibility
    // — pre-existing single-side products are read as front-only with no migration.
    public const META_MOCKUP             = '_pd_mockup_image_id';
    public const META_MOCKUP_BACK        = '_pd_mockup_back_id';

    // Per-color mockups (variable products). Map of attribute-value slug → mockup
    // attachment IDs, plus the variation attribute that drives the lookup (e.g. pa_color).
    // Additive to the product-level mockups above; absent entries fall back to them.
    public const META_MOCKUP_COLOR_ATTR  = '_pd_mockup_color_attr';
    public const META_MOCKUP_BY_COLOR    = '_pd_mockup_by_color';

    // Printable area (zonă imprimabilă). Garment type drives the preset; the
    // per-side rectangle is stored as FRACTIONS (0..1) of the mockup image so it
    // survives the per-color mockup swap and the MAX_INTERNAL_DIM downscale.
    public const META_GARMENT_TYPE       = '_pd_garment_type';
    public const META_PRINT_AREA         = '_pd_print_area';

    // Print sizes (mărimi de imprimeu): the physical width of the printable area
    // above and, per size, the frame in cm, its placement and its price. The
    // customer picks a size per side in the editor; see print_price() for the
    // pricing rule. Schema 1 (before 2026-11) tied one size to a variation
    // attribute — Print_Sizes_Migration converts it.
    public const META_PRINT_SIZES        = '_pd_print_sizes';

    /** Print-size keys, smallest first. Labels come from gettext (print_size_label) so RU translates via the .po. */
    public const PRINT_SIZE_KEYS = [ 'mic', 'mediu', 'mare' ];

    /** Frame placements inside the printable area. `chest` = wearer's left chest on the front, top-center on the back. */
    public const PRINT_ANCHORS = [ 'top', 'center', 'chest' ];

    public const SIDES = [ 'front', 'back' ];

    public const ITEM_META_DESIGN_ID         = '_pd_design_id';
    public const ITEM_META_PREVIEW_URL       = '_pd_design_preview_url';
    public const ITEM_META_PREVIEW_BACK_URL  = '_pd_design_preview_back_url';
    public const ITEM_META_JSON_URL          = '_pd_design_json_url';
    public const ITEM_META_MOCKUP_COLOR      = '_pd_design_mockup_color';

    private Image_Handler $images;

    public function __construct( Image_Handler $images ) {
        $this->images = $images;
    }

    /**
     * Produsul care deține efectiv configurația designerului.
     *
     * De regulă e chiar produsul cerut. Cu Polylang, o traducere creată fără
     * puntea `I18n_Polylang` (sau importată cu alt tool) nu are niciun `_pd_*`;
     * în cazul ăla citim configurația din traducerea care o are, ca produsul
     * tradus să nu apară ca „designer dezactivat". Un `_pd_enabled = 'no'`
     * explicit rămâne respectat — meta există, deci sursa e produsul însuși.
     */
    public function config_source_id( int $product_id ) : int {
        return I18n_Polylang::meta_source_id( $product_id, self::META_ENABLED );
    }

    public function is_enabled_for_product( int $product_id ) : bool {
        return get_post_meta( $this->config_source_id( $product_id ), self::META_ENABLED, true ) === 'yes';
    }

    public function get_product_config( int $product_id ) : array {
        $product_id = $this->config_source_id( $product_id );
        $front_id = (int) get_post_meta( $product_id, self::META_MOCKUP, true );
        $back_id  = (int) get_post_meta( $product_id, self::META_MOCKUP_BACK, true );
        $front_url = $front_id ? (string) wp_get_attachment_image_url( $front_id, 'full' ) : '';
        $back_url  = $back_id  ? (string) wp_get_attachment_image_url( $back_id,  'full' ) : '';
        $print = $this->get_print_area( $product_id );
        return [
            'enabled'              => $this->is_enabled_for_product( $product_id ),
            'mockup_front_id'      => $front_id,
            'mockup_front_url'     => $front_url,
            'mockup_back_id'       => $back_id,
            'mockup_back_url'      => $back_url,
            'garment_type'         => $print['type'],
            'print_area'           => [ 'front' => $print['front'], 'back' => $print['back'] ],
            // Aliases for backwards compatibility with code that still expects the legacy keys.
            'mockup_id'            => $front_id,
            'mockup_url'           => $front_url,
        ];
    }

    /**
     * Preset printable areas per garment type. Values are FRACTIONS (0..1) of the
     * mockup image, measured on the shop's standard mockups so the area matches
     * the largest real print: 28 × 40 cm on apparel (less on the hoodie front,
     * above the pocket), 11 × 6 cm on the cap. The admin fine-tunes them visually
     * on the real mockup afterwards.
     *
     * @return array<string,array{label:string,front:?array,back:?array}>
     */
    public static function garment_presets() : array {
        return [
            'tricou'  => [
                'label' => __( 'Tricou', 'product-designer' ),
                'front' => [ 'x' => 0.356, 'y' => 0.19, 'w' => 0.288, 'h' => 0.412 ],
                'back'  => [ 'x' => 0.361, 'y' => 0.17, 'w' => 0.278, 'h' => 0.398 ],
            ],
            'hanorac' => [
                'label' => __( 'Hanorac', 'product-designer' ),
                'front' => [ 'x' => 0.359, 'y' => 0.24, 'w' => 0.266, 'h' => 0.31 ],
                'back'  => [ 'x' => 0.364, 'y' => 0.27, 'w' => 0.267, 'h' => 0.381 ],
            ],
            'pulover' => [
                'label' => __( 'Pulover', 'product-designer' ),
                'front' => [ 'x' => 0.373, 'y' => 0.17, 'w' => 0.238, 'h' => 0.425 ],
                'back'  => [ 'x' => 0.38, 'y' => 0.17, 'w' => 0.238, 'h' => 0.425 ],
            ],
            'chipiu'  => [
                'label' => __( 'Chipiu', 'product-designer' ),
                'front' => [ 'x' => 0.33, 'y' => 0.27, 'w' => 0.34, 'h' => 0.232 ],
                'back'  => null,
            ],
        ];
    }

    /** „Mic" / „Mediu" / „Mare" in the current language. */
    public static function print_size_label( string $key ) : string {
        switch ( $key ) {
            case 'mic':
                return __( 'Mic', 'product-designer' );
            case 'mediu':
                return __( 'Mediu', 'product-designer' );
            case 'mare':
                return __( 'Mare', 'product-designer' );
        }
        return $key;
    }

    /**
     * The print-size key for a stored slug: `mic` stays `mic`, and the schema-1
     * term slugs (`imprimeu-mic`) found in older configs and designs map to the
     * same key. Unknown slugs give ''.
     */
    public static function print_size_key( string $slug ) : string {
        $slug = sanitize_title( $slug );
        if ( in_array( $slug, self::PRINT_SIZE_KEYS, true ) ) {
            return $slug;
        }
        $short = (string) preg_replace( '/^imprimeu-/', '', $slug );
        return in_array( $short, self::PRINT_SIZE_KEYS, true ) ? $short : '';
    }

    /** @return array{zone_width_cm:float,default:array{front:string,back:string},sizes:array} */
    public static function print_sizes_off() : array {
        return [ 'zone_width_cm' => 0.0, 'default' => [ 'front' => '', 'back' => '' ], 'sizes' => [] ];
    }

    /**
     * Clean a raw print-size config. Returns the "off" shape (no sizes) unless
     * the area width and at least one size are usable.
     *
     * Per size: the frame in cm, its anchor, `price` (what the size adds when it
     * is the only print, or the front of a two-sided design) and `back_price`
     * (what it adds on the back of a two-sided design). A schema-1 config (it
     * has an `attribute` key) is read with `second_side_price` as `back_price`
     * and no `price` — the migration fills `price` from the variation prices.
     *
     * @param mixed $raw
     * @return array{zone_width_cm:float,default:array{front:string,back:string},sizes:array<string,array{w:float,h:float,anchor:string,price:float,back_price:float}>}
     */
    public static function sanitize_print_sizes( $raw ) : array {
        $off = self::print_sizes_off();
        if ( ! is_array( $raw ) ) {
            return $off;
        }
        $legacy = array_key_exists( 'attribute', $raw );
        $zone   = round( (float) ( $raw['zone_width_cm'] ?? 0 ), 1 );
        $money  = static fn( $v ) : float => max( 0.0, (float) wc_format_decimal( $v ?? 0 ) );
        $by_key = [];
        foreach ( (array) ( $raw['sizes'] ?? [] ) as $slug => $size ) {
            $key = self::print_size_key( (string) $slug );
            if ( $key === '' || ! is_array( $size ) ) {
                continue;
            }
            $w = round( (float) ( $size['w'] ?? 0 ), 1 );
            $h = round( (float) ( $size['h'] ?? 0 ), 1 );
            if ( $w <= 0 || $h <= 0 ) {
                continue;
            }
            $anchor         = (string) ( $size['anchor'] ?? '' );
            $by_key[ $key ] = [
                'w'          => $w,
                'h'          => $h,
                'anchor'     => in_array( $anchor, self::PRINT_ANCHORS, true ) ? $anchor : 'top',
                'price'      => $money( $size['price'] ?? 0 ),
                'back_price' => $money( $legacy ? ( $size['second_side_price'] ?? 0 ) : ( $size['back_price'] ?? 0 ) ),
            ];
        }
        // Always smallest first, whatever order the input had.
        $sizes = [];
        foreach ( self::PRINT_SIZE_KEYS as $key ) {
            if ( isset( $by_key[ $key ] ) ) {
                $sizes[ $key ] = $by_key[ $key ];
            }
        }
        if ( $zone <= 0 || ! $sizes ) {
            return $off;
        }
        $keys    = array_keys( $sizes );
        $default = is_array( $raw['default'] ?? null ) ? $raw['default'] : [];
        $front   = self::print_size_key( (string) ( $default['front'] ?? '' ) );
        $back    = self::print_size_key( (string) ( $default['back'] ?? '' ) );
        return [
            'zone_width_cm' => $zone,
            // A small chest logo is the usual front, a large print the usual back.
            'default'       => [
                'front' => isset( $sizes[ $front ] ) ? $front : $keys[0],
                'back'  => isset( $sizes[ $back ] ) ? $back : $keys[ count( $keys ) - 1 ],
            ],
            'sizes'         => $sizes,
        ];
    }

    public function get_print_sizes( int $product_id ) : array {
        return self::sanitize_print_sizes( get_post_meta( $this->config_source_id( $product_id ), self::META_PRINT_SIZES, true ) );
    }

    public function save_print_sizes( int $product_id, array $raw ) : void {
        $clean = self::sanitize_print_sizes( $raw );
        if ( $clean['sizes'] ) {
            update_post_meta( $product_id, self::META_PRINT_SIZES, $clean );
        } else {
            delete_post_meta( $product_id, self::META_PRINT_SIZES );
        }
    }

    /**
     * A print size by key (or schema-1 slug), or null when the product has no
     * print sizes or the slug isn't one of them.
     *
     * @return array{slug:string,w:float,h:float,anchor:string,price:float,back_price:float}|null
     */
    public function print_size_of( int $product_id, string $value ) : ?array {
        $config = $this->get_print_sizes( $product_id );
        $key    = self::print_size_key( $value );
        return ( $key !== '' && isset( $config['sizes'][ $key ] ) ) ? [ 'slug' => $key ] + $config['sizes'][ $key ] : null;
    }

    /**
     * Print sizes for the editor, with labels in the current language and the
     * prices already formatted.
     *
     * @return array{zone_width_cm:float,default:array,sizes:array}|null
     */
    public function print_sizes_for_editor( int $product_id ) : ?array {
        $config = $this->get_print_sizes( $product_id );
        if ( ! $config['sizes'] ) {
            return null;
        }
        $sizes = [];
        foreach ( $config['sizes'] as $key => $size ) {
            $sizes[ $key ] = $size + [
                'label'           => self::print_size_label( $key ),
                'price_text'      => $size['price'] > 0 ? self::price_text( $size['price'] ) : '',
                'back_price_text' => $size['back_price'] > 0 ? self::price_text( $size['back_price'] ) : '',
            ];
        }
        return [
            'zone_width_cm' => $config['zone_width_cm'],
            'default'       => $config['default'],
            'sizes'         => $sizes,
            // So the editor can format the running total like wc_price() does.
            'currency'      => self::currency_format(),
        ];
    }

    /**
     * WooCommerce price format, for formatting amounts in the browser.
     *
     * @return array{symbol:string,position:string,decimals:int,decimal:string,thousand:string}
     */
    public static function currency_format() : array {
        return [
            'symbol'   => html_entity_decode( get_woocommerce_currency_symbol() ),
            'position' => (string) get_option( 'woocommerce_currency_pos', 'left' ),
            'decimals' => (int) wc_get_price_decimals(),
            'decimal'  => (string) wc_get_price_decimal_separator(),
            'thousand' => (string) wc_get_price_thousand_separator(),
        ];
    }

    /**
     * What the print sizes add to the variation price — the one place the rule
     * lives (`src/composables/usePrintPrice.js` mirrors it for the live price).
     *
     * The variation price includes one print of the smallest size. A design on
     * one side (front or back) adds that size's `price`. A design on both sides
     * adds the front size's `price` and the back size's `back_price` — the back
     * is a second print, cheaper than a first one.
     *
     * @param array<string,string> $sizes side => size key, for every printed side
     *                                    ('' when the product has no print sizes).
     * @return array{extra:float,sides:array<string,array{size:string,label:string,w:float,h:float,amount:float}>}
     */
    public function print_price( int $product_id, array $sizes ) : array {
        $config = $this->get_print_sizes( $product_id );
        $both   = isset( $sizes['front'], $sizes['back'] );
        $out    = [ 'extra' => 0.0, 'sides' => [] ];
        foreach ( self::SIDES as $side ) {
            if ( ! array_key_exists( $side, $sizes ) ) {
                continue;
            }
            $key  = self::print_size_key( (string) $sizes[ $side ] );
            $size = $key !== '' ? ( $config['sizes'][ $key ] ?? null ) : null;
            if ( ! $size ) {
                $out['sides'][ $side ] = [ 'size' => '', 'label' => '', 'w' => 0.0, 'h' => 0.0, 'amount' => 0.0 ];
                continue;
            }
            $amount                = ( $both && $side === 'back' ) ? $size['back_price'] : $size['price'];
            $out['sides'][ $side ] = [
                'size'   => $key,
                'label'  => self::print_size_label( $key ),
                'w'      => $size['w'],
                'h'      => $size['h'],
                'amount' => $amount,
            ];
            $out['extra'] += $amount;
        }
        return $out;
    }

    /**
     * „Față: Mic 10 × 10 cm · Spate: Mare 28 × 40 cm (+100 MDL)" — where the
     * design is printed, how big, and what the print sizes added to the price.
     *
     * @param array $price print_price() result.
     */
    public static function print_summary( array $price ) : string {
        $names = [ 'front' => __( 'Față', 'product-designer' ), 'back' => __( 'Spate', 'product-designer' ) ];
        $parts = [];
        foreach ( (array) ( $price['sides'] ?? [] ) as $side => $info ) {
            if ( ! isset( $names[ $side ] ) ) {
                continue;
            }
            $parts[] = empty( $info['size'] )
                ? $names[ $side ]
                : sprintf( '%s: %s %s × %s cm', $names[ $side ], $info['label'], self::cm( (float) $info['w'] ), self::cm( (float) $info['h'] ) );
        }
        $label = implode( ' · ', $parts );
        $extra = (float) ( $price['extra'] ?? 0 );
        if ( $label !== '' && $extra > 0 ) {
            $label .= ' (+' . self::price_text( $extra ) . ')';
        }
        return $label;
    }

    /** 10 → „10", 10.5 → „10,5". */
    private static function cm( float $value ) : string {
        return floor( $value ) == $value ? (string) (int) $value : number_format_i18n( $value, 1 );
    }

    /** Plain-text price, without decimals for whole amounts („75 MDL"). */
    public static function price_text( float $amount ) : string {
        $decimals = floor( $amount ) == $amount ? 0 : wc_get_price_decimals();
        return html_entity_decode( wp_strip_all_tags( wc_price( $amount, [ 'decimals' => $decimals ] ) ) );
    }

    /**
     * Clean the print details the editor sends along with a design: per printed
     * side, the frame (fractions of the mockup), its print size and its size in
     * cm. Kept in the design JSON so production knows where and how big to print,
     * and so the cart can price the design without trusting the browser.
     *
     * @param mixed    $raw   `{ frames: { front: {x,y,w,h,w_cm,h_cm,size}, back: {...} } }`.
     *                        A top-level `size` (older editor builds) applies to every frame.
     * @param string[] $sides The sides the design prints on (printed_sides()).
     * @return array{frames:array<string,array>}|null Null when a printed side has no valid frame or size.
     */
    public function sanitize_print_details( int $product_id, $raw, array $sides ) : ?array {
        if ( ! is_array( $raw ) || ! $sides ) {
            return null;
        }
        $fallback   = (string) ( $raw['size'] ?? '' );
        $raw_frames = is_array( $raw['frames'] ?? null ) ? $raw['frames'] : [];
        $frames     = [];
        foreach ( $sides as $side ) {
            $frame = $raw_frames[ $side ] ?? null;
            if ( ! is_array( $frame ) ) {
                return null;
            }
            $rect = self::sanitize_area( $frame );
            $size = $this->print_size_of( $product_id, (string) ( $frame['size'] ?? $fallback ) );
            if ( ! $rect || ! $size ) {
                return null;
            }
            // The frame never exceeds its size; a smaller value means the area
            // itself was narrower (the cap).
            $w_cm = round( (float) ( $frame['w_cm'] ?? 0 ), 1 );
            $h_cm = round( (float) ( $frame['h_cm'] ?? 0 ), 1 );
            $frames[ $side ] = $rect + [
                'size'   => $size['slug'],
                'w_cm'   => $w_cm > 0 ? min( $w_cm, $size['w'] ) : $size['w'],
                'h_cm'   => $h_cm > 0 ? min( $h_cm, $size['h'] ) : $size['h'],
                'anchor' => $size['anchor'],
            ];
        }
        return [ 'frames' => $frames ];
    }

    /**
     * Side => print-size key for every side a saved design prints on. Designs
     * saved before per-side sizes carry one top-level `print.size` for both.
     *
     * @param array $design get_design_json() result.
     * @return array<string,string>
     */
    public static function design_side_sizes( array $design ) : array {
        $print = is_array( $design['print'] ?? null ) ? $design['print'] : [];
        $out   = [];
        foreach ( self::SIDES as $side ) {
            if ( empty( $design[ $side ] ) || ! is_array( $design[ $side ] ) ) {
                continue;
            }
            $slug         = $print['frames'][ $side ]['size'] ?? ( $print['size'] ?? '' );
            $out[ $side ] = self::print_size_key( (string) $slug );
        }
        return $out;
    }

    /**
     * The sides a submission prints on: a side counts only with objects AND a
     * preview, exactly the sides persist_submission() keeps.
     *
     * @param array        $design   v1 or v2 design payload.
     * @param array|string $previews v2 `{front, back}` data URIs or a legacy single string.
     * @return string[]
     */
    public function printed_sides( array $design, $previews ) : array {
        $normalized = $this->normalize_design_to_v2( $design );
        $previews   = self::normalize_previews( $previews );
        $sides      = [];
        foreach ( self::SIDES as $side ) {
            $has_objects = is_array( $normalized[ $side ] ) && ! empty( $normalized[ $side ]['objects'] );
            $has_preview = is_string( $previews[ $side ] ) && $previews[ $side ] !== '';
            if ( $has_objects && $has_preview ) {
                $sides[] = $side;
            }
        }
        return $sides;
    }

    /**
     * @param array|string $previews
     * @return array{front:mixed,back:mixed}
     */
    private static function normalize_previews( $previews ) : array {
        if ( is_string( $previews ) ) {
            return [ 'front' => $previews, 'back' => '' ];
        }
        if ( ! is_array( $previews ) ) {
            return [ 'front' => '', 'back' => '' ];
        }
        return wp_parse_args( $previews, [ 'front' => '', 'back' => '' ] );
    }

    /**
     * Public URLs of a saved design's preview PNGs, read from disk ('' for a
     * side without one) — the cart uses these instead of the posted URLs.
     *
     * @return array{front:string,back:string}
     */
    public function preview_urls( string $design_id ) : array {
        $out = [];
        foreach ( self::SIDES as $side ) {
            // The front falls back to a legacy single `pd-{id}.png`.
            $png          = $this->images->resolve_preview_path( $design_id, $side );
            $out[ $side ] = is_readable( $png['path'] ) ? (string) $png['url'] : '';
        }
        return $out;
    }

    /**
     * Clamp a raw {x,y,w,h} into a valid in-bounds fraction rectangle, or null if
     * it isn't a usable rectangle (missing/zero size).
     *
     * @param mixed $raw
     */
    public static function sanitize_area( $raw ) : ?array {
        if ( ! is_array( $raw ) ) {
            return null;
        }
        foreach ( [ 'x', 'y', 'w', 'h' ] as $k ) {
            if ( ! isset( $raw[ $k ] ) || $raw[ $k ] === '' || ! is_numeric( $raw[ $k ] ) ) {
                return null;
            }
        }
        $x = min( 1.0, max( 0.0, (float) $raw['x'] ) );
        $y = min( 1.0, max( 0.0, (float) $raw['y'] ) );
        $w = min( 1.0 - $x, max( 0.0, (float) $raw['w'] ) );
        $h = min( 1.0 - $y, max( 0.0, (float) $raw['h'] ) );
        if ( $w <= 0.0 || $h <= 0.0 ) {
            return null;
        }
        $r = static fn( float $n ) : float => round( $n, 4 );
        return [ 'x' => $r( $x ), 'y' => $r( $y ), 'w' => $r( $w ), 'h' => $r( $h ) ];
    }

    /**
     * Read the printable-area config for a product.
     *
     * @return array{type:string, front:?array, back:?array}
     */
    public function get_print_area( int $product_id ) : array {
        $product_id = $this->config_source_id( $product_id );
        $type = (string) get_post_meta( $product_id, self::META_GARMENT_TYPE, true );
        $raw  = get_post_meta( $product_id, self::META_PRINT_AREA, true );
        $front = is_array( $raw ) ? self::sanitize_area( $raw['front'] ?? null ) : null;
        $back  = is_array( $raw ) ? self::sanitize_area( $raw['back'] ?? null )  : null;
        return [ 'type' => $type, 'front' => $front, 'back' => $back ];
    }

    /**
     * Save product-level configuration.
     */
    public function save_product_config( int $product_id, array $config ) : void {
        update_post_meta( $product_id, self::META_ENABLED, $config['enabled'] ? 'yes' : 'no' );

        // Accept both new (mockup_front_id/back_id) and legacy (mockup_id) shapes.
        $front_id = isset( $config['mockup_front_id'] ) ? (int) $config['mockup_front_id'] : (int) ( $config['mockup_id'] ?? 0 );
        $back_id  = (int) ( $config['mockup_back_id'] ?? 0 );

        update_post_meta( $product_id, self::META_MOCKUP,      $front_id );
        update_post_meta( $product_id, self::META_MOCKUP_BACK, $back_id );

        // Printable area (garment type + per-side fraction rectangles).
        if ( array_key_exists( 'garment_type', $config ) ) {
            $garment = sanitize_key( (string) $config['garment_type'] );
            if ( $garment === '' ) {
                delete_post_meta( $product_id, self::META_GARMENT_TYPE );
            } else {
                update_post_meta( $product_id, self::META_GARMENT_TYPE, $garment );
            }
        }
        if ( array_key_exists( 'print_area', $config ) ) {
            $pa    = is_array( $config['print_area'] ) ? $config['print_area'] : [];
            $area  = [];
            $front = self::sanitize_area( $pa['front'] ?? null );
            $back  = self::sanitize_area( $pa['back'] ?? null );
            if ( $front ) {
                $area['front'] = $front;
            }
            if ( $back ) {
                $area['back'] = $back;
            }
            if ( ! empty( $area ) ) {
                update_post_meta( $product_id, self::META_PRINT_AREA, $area );
            } else {
                delete_post_meta( $product_id, self::META_PRINT_AREA );
            }
        }
    }

    /**
     * Read the per-color mockup configuration for a (variable) product.
     *
     * @return array{attribute:string, map:array<string,array{front_id:int,back_id:int,front_url:string,back_url:string}>}
     */
    public function get_color_mockups( int $product_id ) : array {
        $product_id = $this->config_source_id( $product_id );
        $attribute = (string) get_post_meta( $product_id, self::META_MOCKUP_COLOR_ATTR, true );
        $raw       = get_post_meta( $product_id, self::META_MOCKUP_BY_COLOR, true );
        $map       = [];

        if ( is_array( $raw ) ) {
            foreach ( $raw as $value => $sides ) {
                if ( ! is_array( $sides ) ) {
                    continue;
                }
                $front_id = (int) ( $sides['front'] ?? 0 );
                $back_id  = (int) ( $sides['back'] ?? 0 );
                if ( ! $front_id && ! $back_id ) {
                    continue;
                }
                $map[ (string) $value ] = [
                    'front_id'  => $front_id,
                    'back_id'   => $back_id,
                    'front_url' => $front_id ? (string) wp_get_attachment_image_url( $front_id, 'full' ) : '',
                    'back_url'  => $back_id  ? (string) wp_get_attachment_image_url( $back_id,  'full' ) : '',
                ];
            }
        }

        return [ 'attribute' => $attribute, 'map' => $map ];
    }

    /**
     * Per-color mockup options for the editor's in-canvas color switcher:
     * [ ['value'=>'alb','label'=>'Alb','front_url'=>'...','back_url'=>'...'], ... ].
     * Each click swaps the real mockup image (no tint).
     *
     * @return array<int,array{value:string,label:string,front_url:string,back_url:string}>
     */
    public function get_color_options( int $product_id ) : array {
        $colors = $this->get_color_mockups( $product_id );
        if ( $colors['attribute'] === '' || empty( $colors['map'] ) ) {
            return [];
        }
        $attr = $colors['attribute'];
        $out  = [];
        foreach ( $colors['map'] as $slug => $entry ) {
            if ( empty( $entry['front_url'] ) && empty( $entry['back_url'] ) ) {
                continue;
            }
            $term = get_term_by( 'slug', (string) $slug, $attr );
            // Harta e cheiată pe slug-urile din limba produsului-sursă; eticheta
            // o arătăm în limba curentă (atributele `pa_*` sunt traduse de
            // Polylang for WooCommerce).
            $label = ( $term && ! is_wp_error( $term ) )
                ? I18n_Polylang::term_label( $term )
                : ucfirst( (string) $slug );
            // Real swatch color for the editor dot (falls back to '' when the
            // theme has no color set for the term — the UI then uses its own map).
            $hex = ( $term && ! is_wp_error( $term ) )
                ? Validator::sanitize_hex( (string) apply_filters( 'pd_variation_term_color', '', $term, $attr ) )
                : '';
            if ( $hex === '' ) {
                $hex = Validator::color_from_slug( (string) $slug );
            }
            $out[] = [
                'value'     => (string) $slug,
                'label'     => $label,
                'hex'       => $hex,
                'front_url' => $entry['front_url'],
                'back_url'  => $entry['back_url'],
            ];
        }
        return $out;
    }

    /**
     * Persist the per-color mockup configuration.
     *
     * @param string $attribute Variation attribute name that drives mockups (e.g. `pa_color`).
     * @param array  $map       Map of value slug → ['front' => int, 'back' => int].
     */
    public function save_color_mockups( int $product_id, string $attribute, array $map ) : void {
        $attribute = sanitize_text_field( $attribute );

        $clean = [];
        foreach ( $map as $value => $sides ) {
            if ( ! is_array( $sides ) ) {
                continue;
            }
            $front_id = absint( $sides['front'] ?? 0 );
            $back_id  = absint( $sides['back'] ?? 0 );
            if ( ! $front_id && ! $back_id ) {
                continue; // Drop empty rows so the map only holds configured colors.
            }
            $clean[ sanitize_text_field( (string) $value ) ] = [
                'front' => $front_id,
                'back'  => $back_id,
            ];
        }

        if ( $attribute === '' || empty( $clean ) ) {
            delete_post_meta( $product_id, self::META_MOCKUP_BY_COLOR );
            if ( $attribute === '' ) {
                delete_post_meta( $product_id, self::META_MOCKUP_COLOR_ATTR );
            } else {
                update_post_meta( $product_id, self::META_MOCKUP_COLOR_ATTR, $attribute );
            }
            return;
        }

        update_post_meta( $product_id, self::META_MOCKUP_COLOR_ATTR, $attribute );
        update_post_meta( $product_id, self::META_MOCKUP_BY_COLOR, $clean );
    }

    /**
     * Human label for a mockup tint: if the hex matches the swatch color of one
     * of the cart item's variation terms, return the term name („Alb"); custom
     * picker colors that match no term return '' (callers fall back to the hex).
     *
     * @param string $hex       Sanitized #rrggbb.
     * @param array  $variation Cart-item style map: ['attribute_pa_culoare' => 'alb', ...].
     */
    public function color_label_for_variation( string $hex, array $variation ) : string {
        if ( $hex === '' ) {
            return '';
        }
        foreach ( $variation as $key => $slug ) {
            $attr = str_replace( 'attribute_', '', (string) $key );
            $slug = (string) $slug;
            if ( $slug === '' || ! taxonomy_exists( $attr ) ) {
                continue;
            }
            $term = get_term_by( 'slug', $slug, $attr );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $term_hex = Validator::sanitize_hex( apply_filters( 'pd_variation_term_color', '', $term, $attr ) );
            if ( $term_hex !== '' && strcasecmp( $term_hex, $hex ) === 0 ) {
                return (string) $term->name;
            }
        }
        return '';
    }

    /**
     * Resolve the product config for a specific variation, applying the mockup
     * that matches the variation's color, then falling back to the legacy
     * per-variation meta, then to the product-level mockup.
     *
     * Centralizes mockup resolution so the constructor REST and the product-page
     * frontend share identical behaviour.
     */
    public function resolve_config_for_variation( int $product_id, int $variation_id ) : array {
        $config = $this->get_product_config( $product_id );

        if ( ! $variation_id ) {
            return $config;
        }

        // 1) Per-color override (preferred).
        $colors = $this->get_color_mockups( $product_id );
        if ( $colors['attribute'] !== '' && ! empty( $colors['map'] ) ) {
            $value = (string) get_post_meta( $variation_id, 'attribute_' . $colors['attribute'], true );
            // Harta e cheiată pe slug-urile limbii în care a fost salvată, iar
            // metele `_pd_*` se sincronizează neschimbate între traduceri;
            // variația poate fi într-altă limbă, cu alt slug de termen pentru
            // aceeași culoare (`pa_*` e tradusă de PLLWC).
            if ( $value !== '' && ! isset( $colors['map'][ $value ] ) ) {
                $value = I18n_Polylang::match_term_slug(
                    $value,
                    $colors['attribute'],
                    array_map( 'strval', array_keys( $colors['map'] ) )
                );
            }
            if ( $value !== '' && isset( $colors['map'][ $value ] ) ) {
                $entry = $colors['map'][ $value ];
                if ( ! empty( $entry['front_id'] ) ) {
                    $config['mockup_front_id']  = $entry['front_id'];
                    $config['mockup_front_url'] = $entry['front_url'];
                    $config['mockup_id']        = $entry['front_id'];
                    $config['mockup_url']       = $entry['front_url'];
                }
                if ( ! empty( $entry['back_id'] ) ) {
                    $config['mockup_back_id']  = $entry['back_id'];
                    $config['mockup_back_url'] = $entry['back_url'];
                }
            }
        }

        // 2) Legacy per-variation meta override — applied per side only when the
        // per-color map above did NOT already replace the product-level default,
        // so the priority is: per-color > per-variation > product-level.
        $var_front_id = (int) get_post_meta( $variation_id, self::META_MOCKUP, true );
        if ( $var_front_id && (int) $config['mockup_front_id'] === (int) get_post_meta( $product_id, self::META_MOCKUP, true ) ) {
            $config['mockup_front_id']  = $var_front_id;
            $config['mockup_front_url'] = (string) wp_get_attachment_image_url( $var_front_id, 'full' );
            $config['mockup_id']        = $config['mockup_front_id'];
            $config['mockup_url']       = $config['mockup_front_url'];
        }
        $var_back_id = (int) get_post_meta( $variation_id, self::META_MOCKUP_BACK, true );
        if ( $var_back_id && (int) $config['mockup_back_id'] === (int) get_post_meta( $product_id, self::META_MOCKUP_BACK, true ) ) {
            $config['mockup_back_id']  = $var_back_id;
            $config['mockup_back_url'] = (string) wp_get_attachment_image_url( $var_back_id, 'full' );
        }

        return $config;
    }

    /**
     * Store a newly-submitted design on disk and return the metadata payload
     * to be attached to a cart item.
     *
     * @param int    $product_id Product the design targets.
     * @param array  $design     Either v2 shape `{front:{...}, back:{...}}` or
     *                           legacy v1 shape `{objects:[...], ...}`.
     * @param array|string $previews Either v2 shape `['front'=>'data:...','back'=>'data:...']`
     *                               or legacy single string PNG data URI (treated as front).
     * @param string $mockup_color  Optional hex color the customer tinted the mockup with
     *                              ('' = original mockup). Recorded in the JSON for reproduction.
     * @param array|null $print     Print details from sanitize_print_details(), recorded in the JSON.
     * @return array{ok:bool,error?:string,design_id?:string,preview_url?:string,preview_back_url?:string,json_url?:string,sides?:array<string,string>}
     */
    public function persist_submission( int $product_id, array $design, $previews, string $mockup_color = '', ?array $print = null ) : array {
        $design_id = $this->generate_design_id( $product_id );

        // Normalize design payload and previews to v2 shape.
        $normalized = $this->normalize_design_to_v2( $design );
        $previews   = self::normalize_previews( $previews );
        $sides      = $this->printed_sides( $design, $previews );

        $preview_url      = '';
        $preview_back_url = '';

        foreach ( $sides as $side ) {
            $png = $this->images->save_preview_png( $previews[ $side ], $design_id, $side );
            if ( empty( $png['ok'] ) ) {
                return [ 'ok' => false, 'error' => $png['error'] ?? __( 'Eroare preview.', 'product-designer' ) ];
            }
            if ( $side === 'front' ) {
                $preview_url = $png['url'];
            } else {
                $preview_back_url = $png['url'];
            }
        }

        if ( $preview_url === '' && $preview_back_url === '' ) {
            return [ 'ok' => false, 'error' => __( 'Niciun preview valid.', 'product-designer' ) ];
        }

        // Save the combined v2 JSON. A side without a preview is dropped too, so
        // the JSON holds exactly what gets printed and priced.
        $json_payload = [
            'version' => 2,
            'front'   => in_array( 'front', $sides, true ) ? $normalized['front'] : null,
            'back'    => in_array( 'back', $sides, true ) ? $normalized['back'] : null,
        ];
        if ( $mockup_color !== '' ) {
            $json_payload['mockup_color'] = $mockup_color;
        }
        if ( $print ) {
            $json_payload['print'] = $print;
        }
        $json = $this->images->save_design_json( $json_payload, $design_id );
        if ( empty( $json['ok'] ) ) {
            return [ 'ok' => false, 'error' => $json['error'] ?? __( 'Eroare JSON.', 'product-designer' ) ];
        }

        return [
            'ok'               => true,
            'design_id'        => $design_id,
            'preview_url'      => $preview_url,
            'preview_back_url' => $preview_back_url,
            'json_url'         => $json['url'],
            'mockup_color'     => $mockup_color,
            // side => print-size key, for every printed side.
            'sides'            => self::design_side_sizes( $json_payload ),
        ];
    }

    /**
     * Read a design JSON, normalizing legacy v1 (`{objects:[...]}`) into v2
     * (`{version:1, front:{objects:[...]}, back:null}`) so callers can rely on
     * the dual-side shape uniformly.
     */
    public function get_design_json( string $design_id ) : ?array {
        $paths = $this->images->design_paths( $design_id, 'json' );
        if ( ! is_readable( $paths['path'] ) ) {
            return null;
        }
        $decoded = json_decode( (string) file_get_contents( $paths['path'] ), true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }
        return $this->wrap_legacy_for_read( $decoded );
    }

    public function get_design_files( string $design_id ) : array {
        // PNG path uses front-suffix when available, falling back to legacy.
        return [
            'json' => $this->images->design_paths( $design_id, 'json' ),
            'png'  => $this->images->resolve_preview_path( $design_id, 'front' ),
        ];
    }

    /**
     * Side-aware accessor — returns the resolved JSON + PNG paths for one side.
     * Useful when checking back-only designs or building per-side download links.
     */
    public function get_design_files_for_side( string $design_id, string $side ) : array {
        return [
            'json' => $this->images->design_paths( $design_id, 'json' ),
            'png'  => $this->images->resolve_preview_path( $design_id, $side ),
        ];
    }

    /**
     * Normalize incoming design payload to v2 shape regardless of input format.
     * Returns ['front' => ?array, 'back' => ?array] — each side either null or
     * a Fabric canvas serialization with at least an `objects` array.
     */
    public function normalize_design_to_v2( array $design ) : array {
        // Already v2.
        if ( array_key_exists( 'front', $design ) || array_key_exists( 'back', $design ) ) {
            $front = isset( $design['front'] ) && is_array( $design['front'] ) && ! empty( $design['front']['objects'] ) ? $design['front'] : null;
            $back  = isset( $design['back']  ) && is_array( $design['back']  ) && ! empty( $design['back']['objects']  ) ? $design['back']  : null;
            return [ 'front' => $front, 'back' => $back ];
        }
        // Legacy v1: single canvas → treat as front.
        if ( ! empty( $design['objects'] ) ) {
            return [ 'front' => $design, 'back' => null ];
        }
        return [ 'front' => null, 'back' => null ];
    }

    /**
     * Wrap a legacy v1 read result into a v2-shaped array for unified consumption.
     */
    public function wrap_legacy_for_read( array $design ) : array {
        if ( array_key_exists( 'front', $design ) || array_key_exists( 'back', $design ) ) {
            // v2 already.
            return wp_parse_args( $design, [ 'version' => 2, 'front' => null, 'back' => null ] );
        }
        // v1 — treat root as front.
        return [
            'version' => 1,
            'front'   => $design,
            'back'    => null,
        ];
    }

    private function generate_design_id( int $product_id ) : string {
        return sprintf( 'pd-%d-%s', $product_id, wp_generate_password( 16, false, false ) );
    }
}
