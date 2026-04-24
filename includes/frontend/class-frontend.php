<?php
/**
 * Frontend: "Personalizează" button on the product page + editor modal.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Frontend;

use ProductDesigner\Core\Design_Storage;
use ProductDesigner\Core\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Frontend {

    private Design_Storage $storage;

    public function __construct( Design_Storage $storage ) {
        $this->storage = $storage;
    }

    /**
     * Returnează filemtime pe asset-ul dat, cu fallback la PD_VERSION.
     * Forțează cache-busting când editezi JS/CSS în dev.
     */
    private static function asset_version( string $relative_path ) : string {
        $file = PD_PLUGIN_DIR . ltrim( $relative_path, '/' );
        $mtime = file_exists( $file ) ? filemtime( $file ) : false;
        return $mtime ? (string) $mtime : PD_VERSION;
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

        wp_enqueue_style(
            'pd-designer',
            PD_PLUGIN_URL . 'assets/css/designer.css',
            [],
            self::asset_version( 'assets/css/designer.css' )
        );

        // Fabric.js — bundled locally so the editor works offline (Local by Flywheel etc.)
        // and is not blocked by CDN issues.
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
            self::asset_version( 'assets/js/designer.js' ),
            true
        );

        $config    = $this->storage->get_product_config( $product_id );
        $validator = new Validator();
        $settings  = $validator->settings();

        wp_localize_script( 'pd-designer', 'PDData', [
            'rest' => [
                'root'        => esc_url_raw( rest_url( PD_REST_NS . '/' ) ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'uploadPath'  => 'upload',
                'designPath'  => 'design',
            ],
            'product' => [
                'id'         => $product_id,
                'mockup_url' => $config['mockup_url'],
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
                'delete'      => __( 'Șterge',             'product-designer' ),
                'save'        => __( 'Salvează designul',  'product-designer' ),
                'close'       => __( 'Închide',            'product-designer' ),
                'uploading'   => __( 'Se încarcă...',      'product-designer' ),
                'saving'      => __( 'Se salvează...',     'product-designer' ),
                'savedOk'     => __( 'Design salvat.',     'product-designer' ),
                'tooLarge'    => __( 'Fișier prea mare.',  'product-designer' ),
                'badType'     => __( 'Tip neacceptat.',    'product-designer' ),
                'placeholder' => __( 'Textul tău aici',    'product-designer' ),
                'empty'       => __( 'Adaugă cel puțin un element în design.', 'product-designer' ),
            ],
        ] );
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
        ?>
        <div class="pd-frontend-wrap">
            <button type="button" class="button pd-open-designer"><?php esc_html_e( 'Personalizează', 'product-designer' ); ?></button>
            <input type="hidden" name="pd_design_id"   class="pd-design-id"   value="" />
            <input type="hidden" name="pd_preview_url" class="pd-preview-url" value="" />
            <input type="hidden" name="pd_json_url"    class="pd-json-url"    value="" />
            <div class="pd-selected-preview" hidden>
                <img src="" alt="" />
                <button type="button" class="pd-clear-design"><?php esc_html_e( 'Elimină design', 'product-designer' ); ?></button>
            </div>
        </div>

        <div class="pd-modal" hidden aria-hidden="true" role="dialog" aria-modal="true">
            <div class="pd-modal__backdrop"></div>
            <div class="pd-modal__dialog" role="document">
                <header class="pd-modal__header">
                    <h2><?php esc_html_e( 'Editor design', 'product-designer' ); ?></h2>
                    <button type="button" class="pd-modal__close" aria-label="<?php esc_attr_e( 'Închide', 'product-designer' ); ?>">&times;</button>
                </header>
                <div class="pd-modal__body">
                    <aside class="pd-tools">
                        <button type="button" class="pd-tool pd-add-text"><?php esc_html_e( 'Adaugă text', 'product-designer' ); ?></button>
                        <label class="pd-tool pd-upload-btn">
                            <?php esc_html_e( 'Încarcă imagine', 'product-designer' ); ?>
                            <input type="file" class="pd-upload-input" accept="image/png,image/jpeg,image/webp" hidden />
                        </label>
                        <button type="button" class="pd-tool pd-delete" disabled><?php esc_html_e( 'Șterge selecția', 'product-designer' ); ?></button>

                        <div class="pd-text-controls" hidden>
                            <div class="pd-text-controls__title"><?php esc_html_e( 'Proprietăți text', 'product-designer' ); ?></div>
                            <label class="pd-control-label">
                                <?php esc_html_e( 'Culoare', 'product-designer' ); ?>
                                <input type="color" class="pd-text-color" value="#111111" />
                            </label>
                            <label class="pd-control-label">
                                <?php esc_html_e( 'Mărime (px)', 'product-designer' ); ?>
                                <input type="number" class="pd-text-size" min="8" max="600" step="1" value="48" />
                            </label>
                            <label class="pd-control-label">
                                <?php esc_html_e( 'Font', 'product-designer' ); ?>
                                <select class="pd-text-font">
                                    <option value="Arial">Arial</option>
                                    <option value="Helvetica">Helvetica</option>
                                    <option value="Times New Roman">Times New Roman</option>
                                    <option value="Georgia">Georgia</option>
                                    <option value="Courier New">Courier New</option>
                                    <option value="Verdana">Verdana</option>
                                    <option value="Tahoma">Tahoma</option>
                                    <option value="Trebuchet MS">Trebuchet MS</option>
                                    <option value="Impact">Impact</option>
                                    <option value="Comic Sans MS">Comic Sans MS</option>
                                </select>
                            </label>
                            <div class="pd-control-row">
                                <button type="button" class="pd-tool-small pd-text-bold" title="<?php esc_attr_e( 'Bold', 'product-designer' ); ?>"><strong>B</strong></button>
                                <button type="button" class="pd-tool-small pd-text-italic" title="<?php esc_attr_e( 'Italic', 'product-designer' ); ?>"><em>I</em></button>
                                <button type="button" class="pd-tool-small pd-text-underline" title="<?php esc_attr_e( 'Underline', 'product-designer' ); ?>"><u>U</u></button>
                            </div>
                            <label class="pd-control-label">
                                <?php esc_html_e( 'Aliniere', 'product-designer' ); ?>
                                <select class="pd-text-align">
                                    <option value="left"><?php esc_html_e( 'Stânga', 'product-designer' ); ?></option>
                                    <option value="center"><?php esc_html_e( 'Centru', 'product-designer' ); ?></option>
                                    <option value="right"><?php esc_html_e( 'Dreapta', 'product-designer' ); ?></option>
                                </select>
                            </label>
                        </div>

                        <div class="pd-status" aria-live="polite"></div>
                    </aside>
                    <div class="pd-canvas-wrap">
                        <canvas class="pd-canvas"></canvas>
                    </div>
                </div>
                <footer class="pd-modal__footer">
                    <button type="button" class="button pd-cancel"><?php esc_html_e( 'Anulează', 'product-designer' ); ?></button>
                    <button type="button" class="button button-primary pd-save"><?php esc_html_e( 'Salvează designul', 'product-designer' ); ?></button>
                </footer>
            </div>
        </div>
        <?php
    }
}
