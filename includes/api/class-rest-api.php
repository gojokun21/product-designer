<?php
/**
 * REST API: image upload + design persistence.
 *
 * Endpoints (namespace product-designer/v1):
 *   POST /upload                  — multipart file upload (authenticated OR guest w/ nonce)
 *   POST /design                  — persist a design, returns design_id + preview_url
 *   GET  /design/(?P<id>...)      — fetch design JSON (admin only)
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Api;

use ProductDesigner\Core\Design_Storage;
use ProductDesigner\Core\Image_Handler;
use ProductDesigner\Core\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rest_Api {

    private Design_Storage $storage;
    private Image_Handler  $images;
    private Validator      $validator;

    public function __construct( Design_Storage $storage, Image_Handler $images, Validator $validator ) {
        $this->storage   = $storage;
        $this->images    = $images;
        $this->validator = $validator;
    }

    public function register() : void {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function routes() : void {
        register_rest_route( PD_REST_NS, '/upload', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [ $this, 'check_public_nonce' ],
            'callback'            => [ $this, 'handle_upload' ],
        ] );

        register_rest_route( PD_REST_NS, '/design', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => [ $this, 'check_public_nonce' ],
            'callback'            => [ $this, 'handle_save_design' ],
            'args'                => [
                'product_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'design'     => [ 'required' => true, 'type' => 'object' ],
                'preview'    => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( PD_REST_NS, '/design/(?P<id>[A-Za-z0-9_\-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => [ $this, 'check_admin' ],
            'callback'            => [ $this, 'handle_get_design' ],
        ] );
    }

    public function check_public_nonce( WP_REST_Request $request ) {
        if ( ! $this->validator->verify_rest_nonce( $request ) ) {
            return new WP_Error( 'pd_invalid_nonce', __( 'Nonce invalid.', 'product-designer' ), [ 'status' => 403 ] );
        }
        return true;
    }

    public function check_admin() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function handle_upload( WP_REST_Request $request ) {
        $files = $request->get_file_params();
        $file  = $files['file'] ?? null;
        if ( ! is_array( $file ) ) {
            return new WP_Error( 'pd_no_file', __( 'Niciun fișier trimis.', 'product-designer' ), [ 'status' => 400 ] );
        }

        $result = $this->images->handle_upload( $file );
        if ( empty( $result['ok'] ) ) {
            return new WP_Error( 'pd_upload_failed', $result['error'] ?? '', [ 'status' => 400 ] );
        }
        return new WP_REST_Response( [
            'url'           => $result['url'],
            'attachment_id' => $result['attachment_id'],
        ], 201 );
    }

    public function handle_save_design( WP_REST_Request $request ) {
        $product_id = (int) $request->get_param( 'product_id' );
        if ( ! $product_id || ! $this->storage->is_enabled_for_product( $product_id ) ) {
            return new WP_Error( 'pd_product_invalid', __( 'Produs invalid sau designer dezactivat.', 'product-designer' ), [ 'status' => 400 ] );
        }

        $payload = [
            'design'  => $request->get_param( 'design' ),
            'preview' => (string) $request->get_param( 'preview' ),
        ];
        $check = $this->validator->validate_design_payload( $payload );
        if ( empty( $check['ok'] ) ) {
            return new WP_Error( 'pd_design_invalid', $check['error'] ?? '', [ 'status' => 400 ] );
        }

        $persisted = $this->storage->persist_submission( $product_id, $check['design'], $check['preview'] );
        if ( empty( $persisted['ok'] ) ) {
            return new WP_Error( 'pd_persist_failed', $persisted['error'] ?? '', [ 'status' => 500 ] );
        }

        // Stochează designul în WC Session keyed by product_id.
        // Asta face fluxul cart→order robust indiferent de cum face tema
        // add-to-cart: clasic form POST, AJAX, sau Store API (blocks).
        $session_saved = false;
        if ( function_exists( 'WC' ) && WC() ) {
            // În context REST, WC session uneori nu e inițializată încă.
            if ( ! WC()->session ) {
                $handler = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
                if ( class_exists( $handler ) ) {
                    WC()->session = new $handler();
                    WC()->session->init();
                }
            }
            if ( WC()->session ) {
                if ( ! WC()->session->has_session() ) {
                    WC()->session->set_customer_session_cookie( true );
                }
                WC()->session->set( 'pd_design_for_' . $product_id, [
                    'design_id'   => $persisted['design_id'],
                    'preview_url' => $persisted['preview_url'],
                    'json_url'    => $persisted['json_url'],
                    'saved_at'    => time(),
                ] );
                // Save imediat în DB, nu aștepta shutdown.
                if ( method_exists( WC()->session, 'save_data' ) ) {
                    WC()->session->save_data();
                }
                $session_saved = true;
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Product Designer] save_design: product=%d, design_id=%s, session_saved=%s, has_session=%s',
                $product_id,
                $persisted['design_id'],
                $session_saved ? 'da' : 'nu',
                ( function_exists( 'WC' ) && WC()->session && WC()->session->has_session() ) ? 'da' : 'nu'
            ) );
        }

        /**
         * Fires after a design has been persisted from the frontend editor.
         *
         * @param array $persisted { design_id, preview_url, json_url }
         * @param int   $product_id
         */
        do_action( 'pd_design_saved', $persisted, $product_id );

        return new WP_REST_Response( [
            'design_id'     => $persisted['design_id'],
            'preview_url'   => $persisted['preview_url'],
            'json_url'      => $persisted['json_url'],
            'session_saved' => $session_saved,
        ], 201 );
    }

    public function handle_get_design( WP_REST_Request $request ) {
        $id   = (string) $request->get_param( 'id' );
        $json = $this->storage->get_design_json( $id );
        if ( $json === null ) {
            return new WP_Error( 'pd_not_found', __( 'Design inexistent.', 'product-designer' ), [ 'status' => 404 ] );
        }
        return new WP_REST_Response( [ 'design_id' => $id, 'design' => $json ], 200 );
    }
}
