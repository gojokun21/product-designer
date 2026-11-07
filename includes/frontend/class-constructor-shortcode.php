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

use ProductDesigner\Core\I18n_Polylang;
use ProductDesigner\Core\Validator;
use ProductDesigner\Core\Vite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Constructor_Shortcode {

    private Vite $vite;

    public function __construct( Vite $vite ) {
        $this->vite = $vite;
    }

    public function register() : void {
        add_shortcode( PD_CONSTRUCTOR_SHORTCODE, [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts',         [ $this, 'maybe_enqueue' ] );
    }

    public function maybe_enqueue() : void {
        if ( ! $this->is_constructor_context() ) {
            return;
        }

        // jQuery stays a dep for WC events consumed by the editor (parity with Flow A).
        $this->vite->enqueue_entry( 'src/main-constructor.js', 'constructor', [ 'jquery' ] );
    }

    /**
     * PDConstructorData-shaped bootstrap consumed by ConstructorApp via the
     * mount root `data-pd-config` attribute. Per-product editor configs are
     * still fetched at runtime from `constructor/product-config`.
     */
    private function build_bootstrap() : array {
        $validator = new Validator();
        return [
            'title' => $this->get_settings()['title'] ?? __( 'Constructor produse', 'product-designer' ),
            'rest' => [
                'root'  => esc_url_raw( rest_url( PD_REST_NS . '/' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                // Polylang nu deduce limba unui request REST din referer: o ia
                // exclusiv din parametrul lang (PLL_REST_Request::set_language).
                // Fara el, wizard-ul din /ru/ ar lista template-urile romanesti.
                'lang'  => I18n_Polylang::current_lang(),
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
                'changeOptions' => __( 'Schimbă opțiunile',                'product-designer' ),
                'unavailable'   => __( 'Combinația aleasă nu este disponibilă. Alege alte opțiuni.', 'product-designer' ),
                'productsCount' => __( '%d produse',                       'product-designer' ),
                'productCount1' => __( '1 produs',                         'product-designer' ),
                'loading'       => __( 'Se încarcă...',                    'product-designer' ),
                'noProducts'    => __( 'Nu există produse personalizabile în această categorie.', 'product-designer' ),
                'errorLoad'     => __( 'Eroare la încărcare. Reîncearcă.', 'product-designer' ),
                'saveFirst'     => __( 'Salvează designul înainte de a-l adăuga în coș.', 'product-designer' ),
                'addingToCart'  => __( 'Se adaugă în coș...',              'product-designer' ),
                'sideFront'     => __( 'Față',                             'product-designer' ),
                'sideBack'      => __( 'Spate',                            'product-designer' ),
                'addText'       => __( 'Adaugă text',                      'product-designer' ),
                'uploadImg'     => __( 'Încarcă imagine',                  'product-designer' ),
                'delete'        => __( 'Șterge selecția',                  'product-designer' ),
                'uploading'     => __( 'Se încarcă...',                    'product-designer' ),
                'saving'        => __( 'Se salvează...',                   'product-designer' ),
                'savedOk'       => __( 'Design salvat.',                   'product-designer' ),
                'tooLarge'      => __( 'Fișier prea mare.',                'product-designer' ),
                'badType'       => __( 'Tip neacceptat.',                  'product-designer' ),
                'empty'         => __( 'Adaugă cel puțin un element în design.', 'product-designer' ),
                'productColor'      => __( 'Culoare produs',    'product-designer' ),
                'productColorReset' => __( 'Culoare originală', 'product-designer' ),
            ],
        ];
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
        $pd_bootstrap = $this->build_bootstrap();
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
