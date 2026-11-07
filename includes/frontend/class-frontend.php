<?php
/**
 * Frontend: "Personalizează" button on the product page + editor modal.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Frontend;

use ProductDesigner\Core\Design_Storage;
use ProductDesigner\Core\I18n_Polylang;
use ProductDesigner\Core\Validator;
use ProductDesigner\Core\Vite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Frontend {

    private Design_Storage $storage;
    private Vite           $vite;

    public function __construct( Design_Storage $storage, Vite $vite ) {
        $this->storage = $storage;
        $this->vite    = $vite;
    }

    public function register() : void {
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_button_and_modal' ] );
        add_action( 'wp_enqueue_scripts',                    [ $this, 'enqueue' ] );
    }

    public function enqueue() : void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }
        $product_id = (int) get_the_ID();
        if ( ! $this->storage->is_enabled_for_product( $product_id ) ) {
            return;
        }

        // jQuery stays a dep: the cart bridge listens to WC's `adding_to_cart`,
        // `found_variation` and `reset_data` jQuery events.
        $this->vite->enqueue_entry( 'src/main-product.js', 'product', [ 'jquery' ] );
    }

    /**
     * The PDData-shaped config the Vue editor consumes (same contract the old
     * `wp_localize_script( 'PDData' )` used). Passed to the Vue mount root via
     * a `data-pd-config` attribute.
     */
    private function build_config( int $product_id ) : array {
        $config    = $this->storage->get_product_config( $product_id );
        $validator = new Validator();
        $settings  = $validator->settings();

        // Per-variation mockup map so the editor swaps the mockup image when the
        // customer picks a color variation (replaces the old multiply tint).
        $variation_mockups = [];
        $product = wc_get_product( $product_id );
        if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
            foreach ( $product->get_children() as $variation_id ) {
                $vc = $this->storage->resolve_config_for_variation( $product_id, (int) $variation_id );
                $variation_mockups[ (string) (int) $variation_id ] = [
                    'mockup_front_url' => $vc['mockup_front_url'],
                    'mockup_back_url'  => $vc['mockup_back_url'],
                ];
            }
        }

        return [
            'rest' => [
                'root'        => esc_url_raw( rest_url( PD_REST_NS . '/' ) ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'uploadPath'  => 'upload',
                'designPath'  => 'design',
                'lang'        => I18n_Polylang::current_lang(),
            ],
            'product' => [
                'id'                => $product_id,
                'mockup_front_url'  => $config['mockup_front_url'],
                'mockup_back_url'   => $config['mockup_back_url'],
                'variation_mockups' => $variation_mockups,
                'color_options'     => $this->storage->get_color_options( $product_id ),
                'print_area'        => $config['print_area'],
                'print_sizes'       => $this->storage->print_sizes_for_editor( $product_id ),
                'mockup_url'        => $config['mockup_front_url'],
            ],
            'canvas' => [
                'width'  => (int) $settings['canvas_width'],
                'height' => (int) $settings['canvas_height'],
            ],
            'limits' => [
                'maxUploadMB' => (int) $settings['max_upload_size_mb'],
                'mimeTypes'   => $validator->allowed_mime_types(),
            ],
            'i18n' => [
                'customize'   => __( 'Personalizează',     'product-designer' ),
                'addText'     => __( 'Adaugă text',        'product-designer' ),
                'uploadImg'   => __( 'Încarcă imagine',    'product-designer' ),
                'delete'      => __( 'Șterge selecția',    'product-designer' ),
                'save'        => __( 'Salvează designul',  'product-designer' ),
                'cancel'      => __( 'Anulează',           'product-designer' ),
                'close'       => __( 'Închide',            'product-designer' ),
                'uploading'   => __( 'Se încarcă...',      'product-designer' ),
                'saving'      => __( 'Se salvează...',     'product-designer' ),
                'savedOk'     => __( 'Design salvat.',     'product-designer' ),
                'tooLarge'    => __( 'Fișier prea mare.',  'product-designer' ),
                'badType'     => __( 'Tip neacceptat.',    'product-designer' ),
                'placeholder' => __( 'Textul tău aici',    'product-designer' ),
                'empty'       => __( 'Adaugă cel puțin un element în design.', 'product-designer' ),
                'outOfBounds' => __( 'O parte din design iese din zona imprimabilă și nu va fi imprimată.', 'product-designer' ),
                'sideFront'   => __( 'Față',               'product-designer' ),
                'sideBack'    => __( 'Spate',              'product-designer' ),
                'productColor'      => __( 'Culoare produs', 'product-designer' ),
                'productColorReset' => __( 'Culoare originală', 'product-designer' ),
            ] + self::print_strings() + [
                // Blocul „Personalizează" de pe pagina produsului.
                'editDesign'  => __( 'Editează designul', 'product-designer' ),
                'needDesign'  => __( 'Creează-ți designul, apoi îl poți adăuga în coș.', 'product-designer' ),
            ],
        ];
    }

    /**
     * Strings for the print-size picker, frame and price, shared with the
     * Constructor config. `%s` = formatted price.
     *
     * @return array<string,string>
     */
    public static function print_strings() : array {
        return [
            'outOfFrame'    => __( 'O parte din design iese din cadrul imprimeului și nu va fi imprimată. Micșorează-l sau alege un imprimeu mai mare.', 'product-designer' ),
            'fittedToFrame' => __( 'Designul a fost micșorat ca să încapă în cadrul imprimeului ales.', 'product-designer' ),
            'moveFrame'     => __( 'Trage cadrul unde vrei să fie imprimeul', 'product-designer' ),
            'unitCm'        => _x( 'cm', 'centimetri', 'product-designer' ),
            'printSize'     => __( 'Mărime imprimeu', 'product-designer' ),
            /* translators: a print size that adds nothing to the price */
            'printIncluded' => __( 'inclus', 'product-designer' ),
            /* translators: %s: what the print sizes add to the price */
            'printExtra'    => __( 'Imprimeu: +%s la preț', 'product-designer' ),
            'printFront'    => __( 'Imprimeu față', 'product-designer' ),
            'printBack'     => __( 'Imprimeu spate', 'product-designer' ),
            'printTotal'    => __( 'Total', 'product-designer' ),
        ];
    }

    public function render_button_and_modal() : void {
        global $product;
        if ( ! $product instanceof \WC_Product ) {
            return;
        }
        $product_id = (int) $product->get_id();
        if ( ! $this->storage->is_enabled_for_product( $product_id ) ) {
            return;
        }

        $config = $this->build_config( $product_id );
        ?>
        <?php
        // data-state: empty (fără design — „Adaugă în coș" deschide editorul)
        // sau saved (designul e atașat comenzii). Îl schimbă cartBridge.js.
        ?>
        <div class="pd-frontend-wrap" data-state="empty">
            <button type="button" class="button pd-open-designer">
                <svg class="pd-open-designer__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="pd-open-designer__label"><?php esc_html_e( 'Personalizează', 'product-designer' ); ?></span>
            </button>
            <p class="pd-frontend-hint" role="status"><?php esc_html_e( 'Creează-ți designul, apoi îl poți adăuga în coș.', 'product-designer' ); ?></p>
            <input type="hidden" name="pd_design_id"        class="pd-design-id"        value="" />
            <input type="hidden" name="pd_preview_url"      class="pd-preview-url"      value="" />
            <input type="hidden" name="pd_preview_back_url" class="pd-preview-back-url" value="" />
            <input type="hidden" name="pd_json_url"         class="pd-json-url"         value="" />
            <input type="hidden" name="pd_mockup_color"     class="pd-mockup-color-value" value="" />
            <div class="pd-selected-preview" hidden>
                <img class="pd-selected-preview__img" data-side="front" src="" alt="<?php esc_attr_e( 'Față', 'product-designer' ); ?>" hidden />
                <img class="pd-selected-preview__img" data-side="back" src="" alt="<?php esc_attr_e( 'Spate', 'product-designer' ); ?>" hidden />
                <div class="pd-selected-preview__info">
                    <strong class="pd-selected-preview__title"><?php esc_html_e( 'Design salvat', 'product-designer' ); ?></strong>
                    <span class="pd-selected-preview__sides"></span>
                    <button type="button" class="pd-clear-design"><?php esc_html_e( 'Elimină design', 'product-designer' ); ?></button>
                </div>
            </div>
        </div>

        <?php // Vue mount root — ProductDesignerApp renders the editor modal here. ?>
        <div id="pd-designer-app" data-pd-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"></div>
        <?php
    }
}
