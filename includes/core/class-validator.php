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
     * Validate an incoming design payload (Fabric.js canvas JSON + preview PNG data URI).
     *
     * @return array{ok:bool,error?:string,design?:array,preview?:string}
     */
    public function validate_design_payload( $payload ) : array {
        if ( ! is_array( $payload ) ) {
            return [ 'ok' => false, 'error' => __( 'Payload invalid.', 'product-designer' ) ];
        }

        $design  = $payload['design']  ?? null;
        $preview = $payload['preview'] ?? null;

        if ( ! is_array( $design ) || empty( $design['objects'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Designul este gol.', 'product-designer' ) ];
        }

        if ( ! is_string( $preview ) || strpos( $preview, 'data:image/png;base64,' ) !== 0 ) {
            return [ 'ok' => false, 'error' => __( 'Preview PNG invalid.', 'product-designer' ) ];
        }

        // Limit raw JSON size to prevent abuse (512 KB).
        $encoded = wp_json_encode( $design );
        if ( $encoded === false || strlen( $encoded ) > 512 * 1024 ) {
            return [ 'ok' => false, 'error' => __( 'Designul depășește limita permisă.', 'product-designer' ) ];
        }

        // Limit preview size (4 MB base64 ≈ 3 MB binary).
        if ( strlen( $preview ) > 4 * 1024 * 1024 ) {
            return [ 'ok' => false, 'error' => __( 'Preview prea mare.', 'product-designer' ) ];
        }

        return [
            'ok'      => true,
            'design'  => $design,
            'preview' => $preview,
        ];
    }

    public function verify_rest_nonce( \WP_REST_Request $request ) : bool {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
    }
}
