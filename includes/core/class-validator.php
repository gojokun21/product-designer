<?php
/**
 * Validation + sanitization utilities.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Validator {

    public function settings() : array {
        $defaults = [
            'max_upload_size_mb' => 5,
            'allowed_mime_types' => [ 'image/png', 'image/jpeg', 'image/webp' ],
            'canvas_width'       => 600,
            'canvas_height'      => 700,
        ];
        $stored = get_option( 'pd_settings', [] );
        return wp_parse_args( is_array( $stored ) ? $stored : [], $defaults );
    }

    public function max_upload_bytes() : int {
        $mb = (int) $this->settings()['max_upload_size_mb'];
        return max( 1, $mb ) * 1024 * 1024;
    }

    public function allowed_mime_types() : array {
        $types = $this->settings()['allowed_mime_types'];
        return is_array( $types ) ? array_values( $types ) : [];
    }

    public function is_allowed_mime( string $mime ) : bool {
        return in_array( strtolower( $mime ), $this->allowed_mime_types(), true );
    }

    /**
     * Validate an incoming design payload. Accepts both shapes:
     *   - v2 (dual-side): design = {front:{objects:[...]}, back:{objects:[...]}|null},
     *                     preview = {front:'data:...', back:'data:...'|''}
     *   - v1 (legacy):    design = {objects:[...]}, preview = 'data:...'
     *
     * Size limits apply per side (512 KB JSON, 4 MB preview each).
     *
     * @return array{ok:bool,error?:string,design?:array,preview?:array}
     */
    public function validate_design_payload( $payload ) : array {
        if ( ! is_array( $payload ) ) {
            return [ 'ok' => false, 'error' => __( 'Payload invalid.', 'product-designer' ) ];
        }

        $design_in  = $payload['design']  ?? null;
        $preview_in = $payload['preview'] ?? null;

        if ( ! is_array( $design_in ) ) {
            return [ 'ok' => false, 'error' => __( 'Design invalid.', 'product-designer' ) ];
        }

        // Normalize to dual-side shape.
        $is_legacy = ! array_key_exists( 'front', $design_in ) && ! array_key_exists( 'back', $design_in );
        if ( $is_legacy ) {
            $design_in  = [ 'front' => $design_in, 'back' => null ];
            $preview_in = is_string( $preview_in ) ? [ 'front' => $preview_in, 'back' => '' ] : $preview_in;
        }

        if ( ! is_array( $preview_in ) ) {
            return [ 'ok' => false, 'error' => __( 'Preview invalid.', 'product-designer' ) ];
        }

        $design_out  = [ 'front' => null, 'back' => null ];
        $preview_out = [ 'front' => '',   'back' => ''   ];
        $any_side    = false;

        foreach ( [ 'front', 'back' ] as $side ) {
            $side_design  = $design_in[ $side ]  ?? null;
            $side_preview = $preview_in[ $side ] ?? '';

            // A side is "present" only when it has both objects and a preview.
            if ( ! is_array( $side_design ) || empty( $side_design['objects'] ) ) {
                continue;
            }
            if ( ! is_string( $side_preview ) || strpos( $side_preview, 'data:image/png;base64,' ) !== 0 ) {
                return [ 'ok' => false, 'error' => __( 'Preview PNG invalid.', 'product-designer' ) ];
            }

            $encoded = wp_json_encode( $side_design );
            if ( $encoded === false || strlen( $encoded ) > 512 * 1024 ) {
                return [ 'ok' => false, 'error' => __( 'Designul depășește limita permisă.', 'product-designer' ) ];
            }
            if ( strlen( $side_preview ) > 4 * 1024 * 1024 ) {
                return [ 'ok' => false, 'error' => __( 'Preview prea mare.', 'product-designer' ) ];
            }

            $design_out[ $side ]  = $side_design;
            $preview_out[ $side ] = $side_preview;
            $any_side             = true;
        }

        if ( ! $any_side ) {
            return [ 'ok' => false, 'error' => __( 'Designul este gol.', 'product-designer' ) ];
        }

        return [
            'ok'      => true,
            'design'  => $design_out,
            'preview' => $preview_out,
        ];
    }

    public function verify_rest_nonce( \WP_REST_Request $request ) : bool {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
    }
}
