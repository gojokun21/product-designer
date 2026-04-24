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
    public const META_MOCKUP             = '_pd_mockup_image_id';

    public const ITEM_META_DESIGN_ID     = '_pd_design_id';
    public const ITEM_META_PREVIEW_URL   = '_pd_design_preview_url';
    public const ITEM_META_JSON_URL      = '_pd_design_json_url';

    private Image_Handler $images;

    public function __construct( Image_Handler $images ) {
        $this->images = $images;
    }

    public function is_enabled_for_product( int $product_id ) : bool {
        return get_post_meta( $product_id, self::META_ENABLED, true ) === 'yes';
    }

    public function get_product_config( int $product_id ) : array {
        $mockup_id = (int) get_post_meta( $product_id, self::META_MOCKUP, true );
        return [
            'enabled'    => $this->is_enabled_for_product( $product_id ),
            'mockup_id'  => $mockup_id,
            'mockup_url' => $mockup_id ? (string) wp_get_attachment_image_url( $mockup_id, 'full' ) : '',
        ];
    }

    /**
     * Save product-level configuration.
     */
    public function save_product_config( int $product_id, array $config ) : void {
        update_post_meta( $product_id, self::META_ENABLED, $config['enabled'] ? 'yes' : 'no' );
        update_post_meta( $product_id, self::META_MOCKUP,  (int) ( $config['mockup_id'] ?? 0 ) );
    }

    /**
     * Store a newly-submitted design on disk and return the metadata payload
     * to be attached to a cart item.
     *
     * @param int    $product_id Product the design targets.
     * @param array  $design     Fabric.js canvas JSON.
     * @param string $preview    Base64 PNG data URI.
     * @return array{ok:bool,error?:string,design_id?:string,preview_url?:string,json_url?:string}
     */
    public function persist_submission( int $product_id, array $design, string $preview ) : array {
        $design_id = $this->generate_design_id( $product_id );

        $png = $this->images->save_preview_png( $preview, $design_id );
        if ( empty( $png['ok'] ) ) {
            return [ 'ok' => false, 'error' => $png['error'] ?? __( 'Eroare preview.', 'product-designer' ) ];
        }

        $json = $this->images->save_design_json( $design, $design_id );
        if ( empty( $json['ok'] ) ) {
            return [ 'ok' => false, 'error' => $json['error'] ?? __( 'Eroare JSON.', 'product-designer' ) ];
        }

        return [
            'ok'          => true,
            'design_id'   => $design_id,
            'preview_url' => $png['url'],
            'json_url'    => $json['url'],
        ];
    }

    public function get_design_json( string $design_id ) : ?array {
        $paths = $this->images->design_paths( $design_id, 'json' );
        if ( ! is_readable( $paths['path'] ) ) {
            return null;
        }
        $decoded = json_decode( (string) file_get_contents( $paths['path'] ), true );
        return is_array( $decoded ) ? $decoded : null;
    }

    public function get_design_files( string $design_id ) : array {
        return [
            'json' => $this->images->design_paths( $design_id, 'json' ),
            'png'  => $this->images->design_paths( $design_id, 'png' ),
        ];
    }

    private function generate_design_id( int $product_id ) : string {
        return sprintf( 'pd-%d-%s', $product_id, wp_generate_password( 16, false, false ) );
    }
}
