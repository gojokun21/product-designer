<?php
/**
 * "Product Designer" tab on the WooCommerce product edit screen.
 *
 * Uses the native Product Data tabs rather than a standalone metabox so the UI
 * sits alongside other WC panels (Inventory, Shipping, etc).
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Admin;

use ProductDesigner\Core\Design_Storage;
use ProductDesigner\Core\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Product_Metabox {

    public function register() : void {
        add_filter( 'woocommerce_product_data_tabs',   [ $this, 'register_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'render_panel' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save' ], 10, 1 );
    }

    public function register_tab( array $tabs ) : array {
        $tabs['product_designer'] = [
            'label'    => __( 'Product Designer', 'product-designer' ),
            'target'   => 'pd_product_data',
            'class'    => [],
            'priority' => 75,
        ];
        return $tabs;
    }

    public function render_panel() : void {
        global $post;
        $product_id = (int) ( $post->ID ?? 0 );

        $storage = new Design_Storage( new \ProductDesigner\Core\Image_Handler( new Validator() ) );
        $config  = $storage->get_product_config( $product_id );

        wp_nonce_field( 'pd_save_product_' . $product_id, 'pd_product_nonce' );
        ?>
        <div id="pd_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="pd_enabled"><?php esc_html_e( 'Activează designer', 'product-designer' ); ?></label>
                    <input type="checkbox" id="pd_enabled" name="pd_enabled" value="yes" <?php checked( $config['enabled'], true ); ?> />
                    <span class="description"><?php esc_html_e( 'Permite clientului să personalizeze acest produs.', 'product-designer' ); ?></span>
                </p>
            </div>

            <div class="options_group pd-mockup-group" data-side="front">
                <p class="form-field">
                    <label><?php esc_html_e( 'Mockup Față', 'product-designer' ); ?></label>
                    <span class="pd-mockup-preview" data-side="front">
                        <?php if ( $config['mockup_front_url'] ) : ?>
                            <img src="<?php echo esc_url( $config['mockup_front_url'] ); ?>" style="max-width:220px;height:auto;" alt="" />
                        <?php endif; ?>
                    </span>
                    <input type="hidden" id="pd_mockup_image_id" name="pd_mockup_image_id" value="<?php echo esc_attr( (string) $config['mockup_front_id'] ); ?>" />
                    <button type="button" class="button pd-upload-mockup" data-side="front"><?php esc_html_e( 'Selectează imagine', 'product-designer' ); ?></button>
                    <button type="button" class="button pd-remove-mockup" data-side="front" <?php disabled( (bool) $config['mockup_front_id'], false ); ?>><?php esc_html_e( 'Șterge', 'product-designer' ); ?></button>
                </p>
                <p class="description" style="padding:0 12px;">
                    <?php esc_html_e( 'Canvas-ul editorului va avea dimensiunea nativă a acestei imagini. Textele și imaginile adăugate de client sunt returnate în coordonate raportate la pixelii mockup-ului.', 'product-designer' ); ?>
                </p>
            </div>

            <div class="options_group pd-mockup-group" data-side="back">
                <p class="form-field">
                    <label><?php esc_html_e( 'Mockup Spate (opțional)', 'product-designer' ); ?></label>
                    <span class="pd-mockup-preview" data-side="back">
                        <?php if ( $config['mockup_back_url'] ) : ?>
                            <img src="<?php echo esc_url( $config['mockup_back_url'] ); ?>" style="max-width:220px;height:auto;" alt="" />
                        <?php endif; ?>
                    </span>
                    <input type="hidden" id="pd_mockup_back_id" name="pd_mockup_back_id" value="<?php echo esc_attr( (string) $config['mockup_back_id'] ); ?>" />
                    <button type="button" class="button pd-upload-mockup" data-side="back"><?php esc_html_e( 'Selectează imagine', 'product-designer' ); ?></button>
                    <button type="button" class="button pd-remove-mockup" data-side="back" <?php disabled( (bool) $config['mockup_back_id'], false ); ?>><?php esc_html_e( 'Șterge', 'product-designer' ); ?></button>
                </p>
                <p class="description" style="padding:0 12px;">
                    <?php esc_html_e( 'Dacă setezi și mockup-ul de spate, clientul va putea personaliza ambele părți (apar tab-uri Față / Spate în editor).', 'product-designer' ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    public function save( int $product_id ) : void {
        if ( ! isset( $_POST['pd_product_nonce'] ) ) {
            return;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['pd_product_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'pd_save_product_' . $product_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_product', $product_id ) ) {
            return;
        }

        $validator = new Validator();
        $config = [
            'enabled'         => isset( $_POST['pd_enabled'] ) && $_POST['pd_enabled'] === 'yes',
            'mockup_front_id' => isset( $_POST['pd_mockup_image_id'] ) ? absint( $_POST['pd_mockup_image_id'] ) : 0,
            'mockup_back_id'  => isset( $_POST['pd_mockup_back_id'] )  ? absint( $_POST['pd_mockup_back_id'] )  : 0,
        ];

        $storage = new Design_Storage( new \ProductDesigner\Core\Image_Handler( $validator ) );
        $storage->save_product_config( $product_id, $config );
    }
}
