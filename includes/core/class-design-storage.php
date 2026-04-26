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

    public const ITEM_META_DESIGN_ID         = '_pd_design_id';
    public const ITEM_META_PREVIEW_URL       = '_pd_design_preview_url';
    public const ITEM_META_PREVIEW_BACK_URL  = '_pd_design_preview_back_url';
    public const ITEM_META_JSON_URL          = '_pd_design_json_url';

    private Image_Handler $images;

    public function __construct( Image_Handler $images ) {
        $this->images = $images;
    }

    public function is_enabled_for_product( int $product_id ) : bool {
        return get_post_meta( $product_id, self::META_ENABLED, true ) === 'yes';
    }

    public function get_product_config( int $product_id ) : array {
        $front_id = (int) get_post_meta( $product_id, self::META_MOCKUP, true );
        $back_id  = (int) get_post_meta( $product_id, self::META_MOCKUP_BACK, true );
        $front_url = $front_id ? (string) wp_get_attachment_image_url( $front_id, 'full' ) : '';
        $back_url  = $back_id  ? (string) wp_get_attachment_image_url( $back_id,  'full' ) : '';
        return [
            'enabled'          => $this->is_enabled_for_product( $product_id ),
            'mockup_front_id'  => $front_id,
            'mockup_front_url' => $front_url,
            'mockup_back_id'   => $back_id,
            'mockup_back_url'  => $back_url,
            // Aliases for backwards compatibility with code that still expects the legacy keys.
            'mockup_id'        => $front_id,
            'mockup_url'       => $front_url,
        ];
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
     * @return array{ok:bool,error?:string,design_id?:string,preview_url?:string,preview_back_url?:string,json_url?:string}
     */
    public function persist_submission( int $product_id, array $design, $previews ) : array {
        $design_id = $this->generate_design_id( $product_id );

        // Normalize design payload to v2 shape.
        $normalized = $this->normalize_design_to_v2( $design );

        // Normalize previews to v2 shape.
        if ( is_string( $previews ) ) {
            $previews = [ 'front' => $previews, 'back' => '' ];
        } elseif ( ! is_array( $previews ) ) {
            $previews = [ 'front' => '', 'back' => '' ];
        }
        $previews = wp_parse_args( $previews, [ 'front' => '', 'back' => '' ] );

        $preview_url      = '';
        $preview_back_url = '';

        foreach ( [ 'front', 'back' ] as $side ) {
            // Skip sides with no design AND no preview.
            $has_objects = is_array( $normalized[ $side ] ) && ! empty( $normalized[ $side ]['objects'] );
            $has_preview = is_string( $previews[ $side ] ) && $previews[ $side ] !== '';
            if ( ! $has_objects || ! $has_preview ) {
                continue;
            }
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

        // Save the combined v2 JSON.
        $json_payload = [
            'version' => 2,
            'front'   => $normalized['front'],
            'back'    => $normalized['back'],
        ];
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
