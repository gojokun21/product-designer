<?php
/**
 * Shortcode `[pd_constructor]` — pagina de constructor.
 *
 * Render shell HTML cu 3 panouri (categorie, produs, designer) +
 * enqueue assets (designer.js + fabric + constructor.js + CSS).
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Frontend;

use ProductDesigner\Core\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Constructor_Shortcode {

    public function register() : void {
        add_shortcode( PD_CONSTRUCTOR_SHORTCODE, [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts',         [ $this, 'maybe_enqueue' ] );
    }

    public function maybe_enqueue() : void {
        if ( ! $this->is_constructor_context() ) {
            return;
        }

        // Cache-busting via filemtime.
        $css_ver_designer    = self::asset_version( 'assets/css/designer.css' );
        $css_ver_constructor = self::asset_version( 'assets/css/constructor.css' );
        $js_ver_designer     = self::asset_version( 'assets/js/designer.js' );
        $js_ver_constructor  = self::asset_version( 'assets/js/constructor.js' );

        wp_enqueue_style(
            'pd-designer',
            PD_PLUGIN_URL . 'assets/css/designer.css',
            [],
            $css_ver_designer
        );
        wp_enqueue_style(
            'pd-constructor',
            PD_PLUGIN_URL . 'assets/css/constructor.css',
            [ 'pd-designer' ],
            $css_ver_constructor
        );

        wp_enqueue_script(
            'pd-fabric',
            PD_PLUGIN_URL . 'assets/vendor/fabric.min.js',
            [],
            '5.3.1',
            true
        );
        wp_enqueue_script(
            'pd-designer',
            PD_PLUGIN_URL . 'assets/js/designer.js',
            [ 'jquery', 'pd-fabric' ],
            $js_ver_designer,
            true
        );
        wp_enqueue_script(
            'pd-constructor',
            PD_PLUGIN_URL . 'assets/js/constructor.js',
            [ 'jquery', 'pd-designer' ],
            $js_ver_constructor,
            true
        );

        // Bootstrap minim pentru constructor.js — restul vine dinamic via REST.
        $validator = new Validator();
        wp_localize_script( 'pd-constructor', 'PDConstructorData', [
            'rest' => [
                'root'  => esc_url_raw( rest_url( PD_REST_NS . '/' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ],
            'cart' => [
                'addToCartUrl' => esc_url_raw( wc_get_cart_url() ),
            ],
            'limits' => [
                'maxUploadMB' => (int) $validator->settings()['max_upload_size_mb'],
                'mimeTypes'   => $validator->allowed_mime_types(),
            ],
            'i18n' => [
                'pickCategory'  => __( 'Alege o categorie',                'product-designer' ),
                'pickProduct'   => __( 'Alege un produs de bază',          'product-designer' ),
                'customize'     => __( 'Personalizează',                   'product-designer' ),
                'addToCart'     => __( 'Adaugă în coș',                    'product-designer' ),
                'changeCat'     => __( 'Schimbă categoria',                'product-designer' ),
                'changeProduct' => __( 'Schimbă produsul',                 'product-designer' ),
                'productsCount' => __( '%d produse',                       'product-designer' ),
                'productCount1' => __( '1 produs',                         'product-designer' ),
                'loading'       => __( 'Se încarcă...',                    'product-designer' ),
                'noProducts'    => __( 'Nu există produse personalizabile în această categorie.', 'product-designer' ),
                'errorLoad'     => __( 'Eroare la încărcare. Reîncearcă.', 'product-designer' ),
                'saveFirst'     => __( 'Salvează designul înainte de a-l adăuga în coș.', 'product-designer' ),
                'addingToCart'  => __( 'Se adaugă în coș...',              'product-designer' ),
                'designerWaitMockup' => __( 'Aștept mockup...',            'product-designer' ),
            ],
        ] );
    }

    public function render( $atts = [], $content = null ) : string {
        if ( ! is_singular() ) {
            return '';
        }

        // Markup-ul shell (overridable din temă: theme/product-designer/constructor-shell.php).
        $template_path = PD_PLUGIN_DIR . 'templates/constructor-shell.php';
        $theme_override = locate_template( 'product-designer/constructor-shell.php' );
        if ( $theme_override ) {
            $template_path = $theme_override;
        }

        if ( ! file_exists( $template_path ) ) {
            return '';
        }

        ob_start();
        // Variables disponibile în template:
        $constructor_title = $this->get_settings()['title'] ?? __( 'Constructor produse', 'product-designer' );
        include $template_path;
        return (string) ob_get_clean();
    }

    /**
     * Verifică dacă suntem pe o pagină care folosește shortcode-ul.
     */
    private function is_constructor_context() : bool {
        if ( ! is_singular() ) {
            return false;
        }
        $post = get_post();
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }
        return has_shortcode( (string) $post->post_content, PD_CONSTRUCTOR_SHORTCODE );
    }

    private function get_settings() : array {
        $stored = get_option( 'pd_constructor_settings', [] );
        return is_array( $stored ) ? $stored : [];
    }

    private static function asset_version( string $relative_path ) : string {
        $file  = PD_PLUGIN_DIR . ltrim( $relative_path, '/' );
        $mtime = file_exists( $file ) ? filemtime( $file ) : false;
        return $mtime ? (string) $mtime : PD_VERSION;
    }
}
