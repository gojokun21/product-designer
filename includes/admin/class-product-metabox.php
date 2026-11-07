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

            <?php $this->render_print_area_section( $config ); ?>

            <?php $this->render_print_sizes_section( $product_id, $storage ); ?>

            <?php $this->render_color_section( $product_id, $storage ); ?>
        </div>
        <?php
    }

    /**
     * Printable-area editor: garment type + a visual drag/resize rectangle drawn
     * over each mockup. Stored as fractions in `pd_print_area[side][x|y|w|h]`.
     */
    private function render_print_area_section( array $config ) : void {
        $type  = (string) ( $config['garment_type'] ?? '' );
        $area  = is_array( $config['print_area'] ?? null ) ? $config['print_area'] : [];
        $sides = [
            'front' => [ __( 'Zonă imprimabilă — Față', 'product-designer' ), (string) ( $config['mockup_front_url'] ?? '' ) ],
            'back'  => [ __( 'Zonă imprimabilă — Spate', 'product-designer' ), (string) ( $config['mockup_back_url'] ?? '' ) ],
        ];
        ?>
        <div class="options_group pd-print-area-section">
            <p class="form-field">
                <label for="pd_garment_type"><?php esc_html_e( 'Tip produs', 'product-designer' ); ?></label>
                <select id="pd_garment_type" name="pd_garment_type" class="pd-garment-select">
                    <option value=""><?php esc_html_e( '— Personalizat —', 'product-designer' ); ?></option>
                    <?php foreach ( Design_Storage::garment_presets() as $key => $preset ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $type ); ?>>
                            <?php echo esc_html( $preset['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e( 'Alege tipul ca să pornești de la o zonă predefinită, apoi ajusteaz-o cu mouse-ul pe mockup.', 'product-designer' ); ?></span>
            </p>

            <?php foreach ( $sides as $side => $meta ) :
                list( $label, $mockup_url ) = $meta;
                $rect    = isset( $area[ $side ] ) && is_array( $area[ $side ] ) ? $area[ $side ] : null;
                $enabled = (bool) $rect;
                $val     = static function ( $k ) use ( $rect ) {
                    return $rect && isset( $rect[ $k ] ) ? (string) $rect[ $k ] : '';
                };
                ?>
                <div class="pd-pa-editor" data-side="<?php echo esc_attr( $side ); ?>">
                    <p class="form-field">
                        <label>
                            <input type="checkbox" class="pd-pa-enable" <?php checked( $enabled ); ?> <?php echo $mockup_url ? '' : 'disabled'; ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    </p>
                    <?php if ( $mockup_url ) : ?>
                        <div class="pd-pa-stage" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
                            <img class="pd-pa-img" src="<?php echo esc_url( $mockup_url ); ?>" alt="" draggable="false" />
                            <div class="pd-pa-box">
                                <i class="pd-pa-handle" data-h="nw"></i>
                                <i class="pd-pa-handle" data-h="ne"></i>
                                <i class="pd-pa-handle" data-h="se"></i>
                                <i class="pd-pa-handle" data-h="sw"></i>
                            </div>
                        </div>
                    <?php else : ?>
                        <p class="description" style="padding:0 12px;color:#a00;">
                            <?php esc_html_e( 'Setează întâi mockup-ul acestei părți ca să poți delimita zona.', 'product-designer' ); ?>
                        </p>
                    <?php endif; ?>
                    <input type="hidden" class="pd-pa-x" name="pd_print_area[<?php echo esc_attr( $side ); ?>][x]" value="<?php echo esc_attr( $val( 'x' ) ); ?>" />
                    <input type="hidden" class="pd-pa-y" name="pd_print_area[<?php echo esc_attr( $side ); ?>][y]" value="<?php echo esc_attr( $val( 'y' ) ); ?>" />
                    <input type="hidden" class="pd-pa-w" name="pd_print_area[<?php echo esc_attr( $side ); ?>][w]" value="<?php echo esc_attr( $val( 'w' ) ); ?>" />
                    <input type="hidden" class="pd-pa-h" name="pd_print_area[<?php echo esc_attr( $side ); ?>][h]" value="<?php echo esc_attr( $val( 'h' ) ); ?>" />
                </div>
            <?php endforeach; ?>

            <p class="description" style="padding:0 12px;">
                <?php esc_html_e( 'Ce iese din chenar nu apare la imprimare — designul clientului este decupat la această zonă.', 'product-designer' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Print sizes section: the real width of the printable area and, per size
     * (Mic / Mediu / Mare), the frame in cm, where it sits, what it adds to the
     * price and which size each side starts at. The customer picks a size per
     * side in the editor; see Design_Storage::print_price() for the price rule.
     */
    private function render_print_sizes_section( int $product_id, Design_Storage $storage ) : void {
        $config   = $storage->get_print_sizes( $product_id );
        $currency = html_entity_decode( get_woocommerce_currency_symbol() );
        $anchors  = [
            'top'    => __( 'Sus, centrat', 'product-designer' ),
            'chest'  => __( 'Piept stânga (pe spate: sus, centrat)', 'product-designer' ),
            'center' => __( 'Centru', 'product-designer' ),
        ];
        $num = static function ( $size, string $key ) : string {
            return $size ? (string) $size[ $key ] : '';
        };
        ?>
        <div class="options_group pd-print-sizes-section">
            <p class="form-field">
                <label for="pd_print_zone_width"><?php esc_html_e( 'Mărimi de imprimeu', 'product-designer' ); ?></label>
                <input type="number" step="0.1" min="0" id="pd_print_zone_width" name="pd_print_sizes[zone_width_cm]" value="<?php echo esc_attr( $config['zone_width_cm'] ? (string) $config['zone_width_cm'] : '' ); ?>" style="width:90px;" />
                <span class="description"><?php esc_html_e( 'Lățimea reală a zonei imprimabile de mai sus, în cm (ex. 28 pe tricou). Din ea se calculează cadrele. Goală = fără mărimi: cadrul e toată zona.', 'product-designer' ); ?></span>
            </p>

            <div class="pd-print-sizes">
                <table class="widefat striped pd-print-sizes__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Mărime', 'product-designer' ); ?></th>
                            <th><?php esc_html_e( 'Lățime (cm)', 'product-designer' ); ?></th>
                            <th><?php esc_html_e( 'Înălțime (cm)', 'product-designer' ); ?></th>
                            <th><?php esc_html_e( 'Poziție', 'product-designer' ); ?></th>
                            <th><?php
                                /* translators: %s: currency symbol */
                                echo esc_html( sprintf( __( 'Preț (+%s)', 'product-designer' ), $currency ) );
                            ?></th>
                            <th><?php
                                /* translators: %s: currency symbol */
                                echo esc_html( sprintf( __( 'Spate, a doua imprimare (+%s)', 'product-designer' ), $currency ) );
                            ?></th>
                            <th><?php esc_html_e( 'Implicit față', 'product-designer' ); ?></th>
                            <th><?php esc_html_e( 'Implicit spate', 'product-designer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( Design_Storage::PRINT_SIZE_KEYS as $key ) :
                            $size = $config['sizes'][ $key ] ?? null;
                            $base = 'pd_print_sizes[sizes][' . $key . ']';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( Design_Storage::print_size_label( $key ) ); ?></strong></td>
                                <td><input type="number" step="0.1" min="0" name="<?php echo esc_attr( $base . '[w]' ); ?>" value="<?php echo esc_attr( $num( $size, 'w' ) ); ?>" /></td>
                                <td><input type="number" step="0.1" min="0" name="<?php echo esc_attr( $base . '[h]' ); ?>" value="<?php echo esc_attr( $num( $size, 'h' ) ); ?>" /></td>
                                <td>
                                    <select name="<?php echo esc_attr( $base . '[anchor]' ); ?>">
                                        <?php foreach ( $anchors as $value => $label ) : ?>
                                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $size ? $size['anchor'] : 'top' ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" step="1" min="0" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $num( $size, 'price' ) ); ?>" /></td>
                                <td><input type="number" step="1" min="0" name="<?php echo esc_attr( $base . '[back_price]' ); ?>" value="<?php echo esc_attr( $num( $size, 'back_price' ) ); ?>" /></td>
                                <td><input type="radio" name="pd_print_sizes[default][front]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $config['default']['front'], $key ); ?> /></td>
                                <td><input type="radio" name="pd_print_sizes[default][back]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $config['default']['back'], $key ); ?> /></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="description" style="padding:0 12px;">
                <?php esc_html_e( 'Clientul alege mărimea separat pentru față și pentru spate, în editor. Prețul variației include un imprimeu; „Preț" e cât adaugă mărimea când e singurul imprimeu (sau pe față), iar „Spate, a doua imprimare" e cât adaugă pe spate când există design și pe față. Un rând fără lățime și înălțime nu e oferit.', 'product-designer' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Per-color mockup section. Only meaningful for variable products: lets the
     * admin assign a different front/back mockup to each value of the attribute
     * that drives mockups (typically the color attribute). Falls back to the
     * product-level mockup for colors left empty.
     */
    private function render_color_section( int $product_id, Design_Storage $storage ) : void {
        $product = $product_id ? wc_get_product( $product_id ) : null;
        if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
            return;
        }

        $attributes = $this->product_attributes_data( $product );
        if ( empty( $attributes ) ) {
            return;
        }

        $colors        = $storage->get_color_mockups( $product_id );
        $saved_attr    = $colors['attribute'];
        $saved_map     = $colors['map'];
        $current_attr  = $saved_attr !== '' ? $saved_attr : $this->guess_color_attribute( $attributes );
        ?>
        <div class="options_group pd-color-section">
            <p class="form-field">
                <label for="pd_mockup_color_attr"><?php esc_html_e( 'Mockup per culoare', 'product-designer' ); ?></label>
                <select id="pd_mockup_color_attr" name="pd_mockup_color_attr" class="pd-color-attr-select">
                    <?php foreach ( $attributes as $attr ) : ?>
                        <option value="<?php echo esc_attr( $attr['name'] ); ?>" <?php selected( $attr['name'], $current_attr ); ?>>
                            <?php echo esc_html( $attr['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e( 'Alege atributul care schimbă mockup-ul (de obicei Culoare).', 'product-designer' ); ?></span>
            </p>

            <?php foreach ( $attributes as $attr ) :
                $is_active = ( $attr['name'] === $current_attr );
                ?>
                <div class="pd-color-mockups" data-attr="<?php echo esc_attr( $attr['name'] ); ?>" <?php echo $is_active ? '' : 'hidden'; ?>>
                    <?php foreach ( $attr['options'] as $opt ) :
                        // Prefill only from the saved map for the saved attribute.
                        $entry     = ( $attr['name'] === $saved_attr && isset( $saved_map[ $opt['value'] ] ) ) ? $saved_map[ $opt['value'] ] : null;
                        $front_id  = $entry ? (int) $entry['front_id']  : 0;
                        $back_id   = $entry ? (int) $entry['back_id']   : 0;
                        $front_url = $entry ? (string) $entry['front_url'] : '';
                        $back_url  = $entry ? (string) $entry['back_url']  : '';
                        $base      = 'pd_color[' . $attr['name'] . '][' . $opt['value'] . ']';
                        ?>
                        <div class="pd-color-row">
                            <span class="pd-color-row__name">
                                <?php if ( ! empty( $opt['hex'] ) ) : ?>
                                    <span class="pd-color-dot" style="background:<?php echo esc_attr( $opt['hex'] ); ?>;"></span>
                                <?php endif; ?>
                                <?php echo esc_html( $opt['label'] ); ?>
                            </span>
                            <div class="pd-color-row__fields">
                                <?php
                                $this->render_color_field( $base . '[front]', $front_id, $front_url, __( 'Față', 'product-designer' ), 'front' );
                                $this->render_color_field( $base . '[back]',  $back_id,  $back_url,  __( 'Spate', 'product-designer' ), 'back' );
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <p class="description" style="padding:0 12px;">
                <?php esc_html_e( 'Culorile fără mockup propriu folosesc mockup-ul global de mai sus.', 'product-designer' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * A single mockup picker (one side, one color). Wrapped in [data-pd-field] so
     * the shared admin.js media picker resolves the hidden input + preview from
     * the button context instead of fixed IDs.
     */
    private function render_color_field( string $name, int $attachment_id, string $url, string $side_label, string $side ) : void {
        ?>
        <div class="pd-mockup-field" data-pd-field data-side="<?php echo esc_attr( $side ); ?>">
            <span class="pd-mockup-field__label"><?php echo esc_html( $side_label ); ?></span>
            <span class="pd-mockup-preview" data-side="<?php echo esc_attr( $side ); ?>">
                <?php if ( $url ) : ?>
                    <img src="<?php echo esc_url( $url ); ?>" style="max-width:90px;height:auto;" alt="" />
                <?php endif; ?>
            </span>
            <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
            <button type="button" class="button button-small pd-upload-mockup" data-side="<?php echo esc_attr( $side ); ?>"><?php esc_html_e( 'Selectează', 'product-designer' ); ?></button>
            <button type="button" class="button button-small pd-remove-mockup" data-side="<?php echo esc_attr( $side ); ?>" <?php disabled( (bool) $attachment_id, false ); ?>><?php esc_html_e( 'Șterge', 'product-designer' ); ?></button>
        </div>
        <?php
    }

    /**
     * Product attributes with all of their assigned option labels.
     *
     * Uses every term the product has for each attribute (not only the values
     * used for variations), so a mockup slot appears for each color the product
     * actually carries in its attributes. Each option carries the theme swatch
     * color when available, for the admin color dot.
     *
     * @return array<int,array{name:string,label:string,options:array<int,array{value:string,label:string,hex:string}>}>
     */
    private function product_attributes_data( \WC_Product $product ) : array {
        $out = [];
        foreach ( $product->get_attributes() as $attribute ) {
            if ( ! $attribute instanceof \WC_Product_Attribute ) {
                continue;
            }
            $attr_name = (string) $attribute->get_name();
            $options   = [];

            if ( $attribute->is_taxonomy() ) {
                // All terms assigned to the product for this attribute — includes
                // display-only ones that get_variation_attributes() would drop.
                foreach ( (array) $attribute->get_terms() as $term ) {
                    if ( ! $term instanceof \WP_Term ) {
                        continue;
                    }
                    $options[] = [
                        'value' => (string) $term->slug,
                        'label' => (string) $term->name,
                        'hex'   => $this->term_swatch_hex( $term, $attr_name ),
                    ];
                }
            } else {
                foreach ( $attribute->get_options() as $val ) {
                    $options[] = [
                        'value' => (string) $val,
                        'label' => (string) $val,
                        'hex'   => '',
                    ];
                }
            }

            if ( empty( $options ) ) {
                continue;
            }

            $out[] = [
                'name'    => $attr_name,
                'label'   => (string) wc_attribute_label( $attr_name, $product ),
                'options' => $options,
            ];
        }
        return $out;
    }

    /**
     * Resolve the theme swatch color for a term ('' when none is set), reusing
     * the same `pd_variation_term_color` filter the editor/cart already rely on.
     */
    private function term_swatch_hex( \WP_Term $term, string $attr_name ) : string {
        $hex = Validator::sanitize_hex( (string) apply_filters( 'pd_variation_term_color', '', $term, $attr_name ) );
        // Fall back to a slug-based color so common colors still show a dot even
        // when no explicit swatch color is configured on the term.
        return $hex !== '' ? $hex : Validator::color_from_slug( $term->slug );
    }

    /**
     * Pick the attribute most likely to be "color" by name/label heuristics.
     */
    private function guess_color_attribute( array $attributes ) : string {
        foreach ( $attributes as $attr ) {
            $haystack = strtolower( $attr['name'] . ' ' . $attr['label'] );
            if ( strpos( $haystack, 'color' ) !== false
                || strpos( $haystack, 'colour' ) !== false
                || strpos( $haystack, 'culoare' ) !== false
                || strpos( $haystack, 'culori' ) !== false ) {
                return $attr['name'];
            }
        }
        return $attributes[0]['name'] ?? '';
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
            'garment_type'    => isset( $_POST['pd_garment_type'] ) ? sanitize_key( wp_unslash( $_POST['pd_garment_type'] ) ) : '',
            'print_area'      => $this->read_print_area_post(),
        ];

        $storage = new Design_Storage( new \ProductDesigner\Core\Image_Handler( $validator ) );
        $storage->save_product_config( $product_id, $config );

        $this->save_color_mockups( $product_id, $storage );
        $this->save_print_sizes( $product_id, $storage );
    }

    /**
     * Persist the print sizes table. Design_Storage::sanitize_print_sizes()
     * does the cleaning; an empty zone width or no usable row deletes the meta.
     */
    private function save_print_sizes( int $product_id, Design_Storage $storage ) : void {
        if ( ! isset( $_POST['pd_print_sizes'] ) || ! is_array( $_POST['pd_print_sizes'] ) ) {
            return;
        }
        $raw = wp_unslash( $_POST['pd_print_sizes'] );
        $storage->save_print_sizes( $product_id, [
            'zone_width_cm' => $raw['zone_width_cm'] ?? 0,
            'default'       => is_array( $raw['default'] ?? null ) ? $raw['default'] : [],
            'sizes'         => is_array( $raw['sizes'] ?? null ) ? $raw['sizes'] : [],
        ] );
    }

    /**
     * Read the raw per-side printable-area rectangles from POST. Values are the
     * fractions the drag editor wrote into the hidden inputs; Design_Storage does
     * the clamping/validation, dropping any side that isn't a usable rectangle.
     *
     * @return array{front:?array,back:?array}
     */
    private function read_print_area_post() : array {
        $raw = ( isset( $_POST['pd_print_area'] ) && is_array( $_POST['pd_print_area'] ) )
            ? wp_unslash( $_POST['pd_print_area'] )
            : [];
        $pick = static function ( $side ) use ( $raw ) : ?array {
            if ( ! isset( $raw[ $side ] ) || ! is_array( $raw[ $side ] ) ) {
                return null;
            }
            $out = [];
            foreach ( [ 'x', 'y', 'w', 'h' ] as $k ) {
                $out[ $k ] = isset( $raw[ $side ][ $k ] ) ? (string) $raw[ $side ][ $k ] : '';
            }
            return $out;
        };
        return [ 'front' => $pick( 'front' ), 'back' => $pick( 'back' ) ];
    }

    /**
     * Persist the per-color mockup map for the attribute selected in the UI.
     */
    private function save_color_mockups( int $product_id, Design_Storage $storage ) : void {
        $attribute = isset( $_POST['pd_mockup_color_attr'] )
            ? sanitize_text_field( wp_unslash( $_POST['pd_mockup_color_attr'] ) )
            : '';

        $map = [];
        if ( $attribute !== '' && isset( $_POST['pd_color'][ $attribute ] ) && is_array( $_POST['pd_color'][ $attribute ] ) ) {
            // Only the selected attribute's rows are persisted; stale data for other
            // attributes (e.g. after switching the driving attribute) is dropped.
            $rows = wp_unslash( $_POST['pd_color'][ $attribute ] );
            foreach ( $rows as $value => $sides ) {
                if ( ! is_array( $sides ) ) {
                    continue;
                }
                $map[ (string) $value ] = [
                    'front' => isset( $sides['front'] ) ? absint( $sides['front'] ) : 0,
                    'back'  => isset( $sides['back'] )  ? absint( $sides['back'] )  : 0,
                ];
            }
        }

        $storage->save_color_mockups( $product_id, $attribute, $map );
    }
}
