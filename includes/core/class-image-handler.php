<?php
/**
 * Handles image uploads and preview persistence to disk.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Image_Handler {

    private Validator $validator;

    public function __construct( Validator $validator ) {
        $this->validator = $validator;
    }

    /**
     * Handle a user-uploaded image from a REST request.
     *
     * @param array $file  Entry from $_FILES.
     * @return array{ok:bool,error?:string,url?:string,attachment_id?:int}
     */
    public function handle_upload( array $file ) : array {
        if ( ! isset( $file['tmp_name'], $file['name'], $file['size'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Fișier invalid.', 'product-designer' ) ];
        }

        // Verifică PHP upload error codes (ex. fișier depășește upload_max_filesize în php.ini).
        $upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
        if ( $upload_error !== UPLOAD_ERR_OK ) {
            $map = [
                UPLOAD_ERR_INI_SIZE   => __( 'Fișier prea mare (limită server).', 'product-designer' ),
                UPLOAD_ERR_FORM_SIZE  => __( 'Fișier prea mare (limită formular).', 'product-designer' ),
                UPLOAD_ERR_PARTIAL    => __( 'Upload parțial. Reîncearcă.', 'product-designer' ),
                UPLOAD_ERR_NO_FILE    => __( 'Niciun fișier trimis.', 'product-designer' ),
                UPLOAD_ERR_NO_TMP_DIR => __( 'Server fără folder temporar.', 'product-designer' ),
                UPLOAD_ERR_CANT_WRITE => __( 'Scriere pe disc eșuată.', 'product-designer' ),
                UPLOAD_ERR_EXTENSION  => __( 'Upload blocat de server.', 'product-designer' ),
            ];
            return [ 'ok' => false, 'error' => $map[ $upload_error ] ?? __( 'Eroare upload.', 'product-designer' ) ];
        }

        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Upload suspect.', 'product-designer' ) ];
        }

        if ( (int) $file['size'] > $this->validator->max_upload_bytes() ) {
            return [ 'ok' => false, 'error' => __( 'Fișier prea mare.', 'product-designer' ) ];
        }

        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        $mime  = $check['type'] ?? '';
        if ( ! $this->validator->is_allowed_mime( (string) $mime ) ) {
            return [ 'ok' => false, 'error' => __( 'Tip fișier neacceptat.', 'product-designer' ) ];
        }

        // Additional defense: verify image via getimagesize.
        $info = @getimagesize( $file['tmp_name'] );
        if ( ! $info || ! isset( $info['mime'] ) || ! $this->validator->is_allowed_mime( $info['mime'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Fișierul nu este o imagine validă.', 'product-designer' ) ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $mime_map = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg|jpeg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        $mimes = [];
        foreach ( $this->validator->allowed_mime_types() as $allowed ) {
            if ( isset( $mime_map[ $allowed ] ) ) {
                $mimes[ $mime_map[ $allowed ] ] = $allowed;
            }
        }

        $overrides = [
            'test_form' => false,
            'mimes'     => $mimes,
        ];

        $moved = wp_handle_upload( $file, $overrides );
        if ( isset( $moved['error'] ) ) {
            return [ 'ok' => false, 'error' => (string) $moved['error'] ];
        }

        $attachment = [
            'post_mime_type' => $moved['type'],
            'post_title'     => sanitize_file_name( pathinfo( $moved['file'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attachment_id = wp_insert_attachment( $attachment, $moved['file'] );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return [ 'ok' => false, 'error' => __( 'Nu s-a putut salva atașamentul.', 'product-designer' ) ];
        }
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $moved['file'] ) );

        return [
            'ok'            => true,
            'url'           => $moved['url'],
            'attachment_id' => (int) $attachment_id,
        ];
    }

    /**
     * Persist a base64 PNG preview to /uploads/product-designer/ and return its public URL.
     *
     * @param string      $data_uri   data:image/png;base64,...
     * @param string      $design_id
     * @param string|null $side       'front' | 'back' | null (legacy: no suffix).
     */
    public function save_preview_png( string $data_uri, string $design_id, ?string $side = null ) : array {
        if ( strpos( $data_uri, 'data:image/png;base64,' ) !== 0 ) {
            return [ 'ok' => false, 'error' => __( 'Format preview invalid.', 'product-designer' ) ];
        }
        $binary = base64_decode( substr( $data_uri, strlen( 'data:image/png;base64,' ) ), true );
        if ( $binary === false ) {
            return [ 'ok' => false, 'error' => __( 'Preview PNG corupt.', 'product-designer' ) ];
        }

        $paths = $this->design_paths( $design_id, 'png', $side );
        if ( ! wp_mkdir_p( dirname( $paths['path'] ) ) ) {
            return [ 'ok' => false, 'error' => __( 'Nu s-a putut crea folderul de upload.', 'product-designer' ) ];
        }
        if ( file_put_contents( $paths['path'], $binary ) === false ) {
            return [ 'ok' => false, 'error' => __( 'Scriere preview eșuată.', 'product-designer' ) ];
        }
        return [ 'ok' => true, 'path' => $paths['path'], 'url' => $paths['url'] ];
    }

    public function save_design_json( array $design, string $design_id ) : array {
        $paths = $this->design_paths( $design_id, 'json' );
        if ( ! wp_mkdir_p( dirname( $paths['path'] ) ) ) {
            return [ 'ok' => false, 'error' => __( 'Nu s-a putut crea folderul.', 'product-designer' ) ];
        }
        $encoded = wp_json_encode( $design );
        if ( $encoded === false ) {
            return [ 'ok' => false, 'error' => __( 'JSON invalid.', 'product-designer' ) ];
        }
        if ( file_put_contents( $paths['path'], $encoded ) === false ) {
            return [ 'ok' => false, 'error' => __( 'Scriere JSON eșuată.', 'product-designer' ) ];
        }
        return [ 'ok' => true, 'path' => $paths['path'], 'url' => $paths['url'] ];
    }

    /**
     * Return absolute path + public URL for a design asset on disk.
     *
     * @param string      $design_id
     * @param string      $ext   'json' | 'png'
     * @param string|null $side  'front' | 'back' | null. Suffix applied only for png with explicit side.
     *                           For json, suffix is ignored (one combined JSON per design).
     */
    public function design_paths( string $design_id, string $ext, ?string $side = null ) : array {
        $uploads = wp_upload_dir();
        $safe_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', $design_id );
        $dir     = trailingslashit( $uploads['basedir'] ) . PD_UPLOAD_SUBDIR;
        $url_dir = trailingslashit( $uploads['baseurl'] ) . PD_UPLOAD_SUBDIR;

        $suffix = '';
        if ( $ext === 'png' && in_array( $side, [ 'front', 'back' ], true ) ) {
            $suffix = '-' . $side;
        }

        return [
            'path' => $dir . '/' . $safe_id . $suffix . '.' . $ext,
            'url'  => $url_dir . '/' . $safe_id . $suffix . '.' . $ext,
        ];
    }

    /**
     * Locate the best available preview PNG for a design, accommodating both
     * legacy single-side files (`pd-{id}.png`) and dual-side files
     * (`pd-{id}-front.png` / `-back.png`). Returns paths even if the file is
     * missing; caller decides whether to verify with is_readable().
     *
     * @return array{path:string,url:string,side:?string}
     */
    public function resolve_preview_path( string $design_id, ?string $side = null ) : array {
        if ( $side === 'front' || $side === 'back' ) {
            $with_suffix = $this->design_paths( $design_id, 'png', $side );
            if ( is_readable( $with_suffix['path'] ) ) {
                return $with_suffix + [ 'side' => $side ];
            }
            // Legacy fallback: a single `pd-{id}.png` may stand in for "front".
            if ( $side === 'front' ) {
                $legacy = $this->design_paths( $design_id, 'png', null );
                if ( is_readable( $legacy['path'] ) ) {
                    return $legacy + [ 'side' => null ];
                }
            }
            return $with_suffix + [ 'side' => $side ];
        }

        // No side requested: prefer front-suffixed, then legacy.
        $front  = $this->design_paths( $design_id, 'png', 'front' );
        if ( is_readable( $front['path'] ) ) {
            return $front + [ 'side' => 'front' ];
        }
        $legacy = $this->design_paths( $design_id, 'png', null );
        return $legacy + [ 'side' => null ];
    }
}
