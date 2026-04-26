<?php
/**
 * REST endpoints pentru pagina Constructor.
 *
 * Toate endpointurile sunt PUBLICE (no auth) — clienții ne-logați trebuie să
 * navigheze categoriile și să aleagă produsul. Securitate la save vine prin
 * existing /design endpoint care folosește nonce.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Api;

use ProductDesigner\Core\Design_Storage;
use ProductDesigner\Core\Templates;
use ProductDesigner\Core\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rest_Constructor {

    private Design_Storage $storage;
    private Validator      $validator;

    public function __construct( Design_Storage $storage, Validator $validator ) {
        $this->storage   = $storage;
        $this->validator = $validator;
    }

    public function register() : void {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function routes() : void {
        register_rest_route( PD_REST_NS, '/constructor/categories', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'handle_categories' ],
        ] );

        register_rest_route( PD_REST_NS, '/constructor/products', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'handle_products' ],
            'args'                => [
                'category_id' => [ 'required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'page'        => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'per_page'    => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( PD_REST_NS, '/constructor/product-config', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'handle_product_config' ],
            'args'                => [
                'product_id'   => [ 'required' => true,  'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'variation_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( PD_REST_NS, '/constructor/variations', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback'            => [ $this, 'handle_variations' ],
            'args'                => [
                'product_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    /**
     * GET /constructor/categories
     * Returnează categoriile din taxonomia `pd_template_cat` care conțin
     * cel puțin un template publicat.
     */
    public function handle_categories( WP_REST_Request $request ) {
        nocache_headers();

        $items = [];
        $terms = get_terms( [
            'taxonomy'   => Templates::TAXONOMY,
            'hide_empty' => false,
        ] );

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $count = $this->count_templates_in_term( (int) $term->term_id );
                if ( $count === 0 ) {
                    continue;
                }
                $icon_id = (int) get_term_meta( (int) $term->term_id, 'pd_icon_id', true );
                $items[] = $this->format_category( $term, $count, 0, $icon_id );
            }
            // Sortare alfabetică.
            usort( $items, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
        }

        return new WP_REST_Response( [ 'items' => $items ], 200 );
    }

    /**
     * GET /constructor/products?category_id=X&page=N&per_page=12
     */
    public function handle_products( WP_REST_Request $request ) {
        nocache_headers();

        $category_id = (int) $request->get_param( 'category_id' );
        $page        = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $per_page    = max( 1, min( 48, (int) ( $request->get_param( 'per_page' ) ?: 12 ) ) );

        if ( ! $category_id ) {
            return new WP_Error( 'pd_invalid_category', __( 'Categorie invalidă.', 'product-designer' ), [ 'status' => 400 ] );
        }

        $query = new \WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'no_found_rows'  => false,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => Templates::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                ],
            ],
            'meta_query'     => [
                [
                    'key'   => Templates::META_IS_TEMPLATE,
                    'value' => 'yes',
                ],
            ],
        ] );

        $items = [];
        foreach ( $query->posts as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product instanceof \WC_Product ) {
                continue;
            }
            $config         = $this->storage->get_product_config( (int) $product_id );
            $thumbnail_id   = (int) $product->get_image_id();
            $thumbnail_url  = $thumbnail_id ? (string) wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';
            // Fallback la mockup dacă nu are featured image.
            if ( ! $thumbnail_url && ! empty( $config['mockup_url'] ) ) {
                $thumbnail_url = $config['mockup_url'];
            }

            $items[] = [
                'id'            => (int) $product_id,
                'name'          => $product->get_name(),
                'price_html'    => $product->get_price_html(),
                'thumbnail_url' => $thumbnail_url,
                'mockup_url'    => $config['mockup_url'],
                'is_variable'   => $product->is_type( 'variable' ),
                'permalink'     => get_permalink( $product_id ),
            ];
        }

        return new WP_REST_Response( [
            'items'       => $items,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page'        => $page,
            'per_page'    => $per_page,
        ], 200 );
    }

    /**
     * GET /constructor/product-config?product_id=Y[&variation_id=Z]
     * Returnează exact shape-ul PDData pentru un produs (sau o variație).
     */
    public function handle_product_config( WP_REST_Request $request ) {
        nocache_headers();

        $product_id   = (int) $request->get_param( 'product_id' );
        $variation_id = (int) $request->get_param( 'variation_id' );

        if ( ! $product_id || ! $this->storage->is_enabled_for_product( $product_id ) ) {
            return new WP_Error( 'pd_product_invalid', __( 'Produs invalid sau designer dezactivat.', 'product-designer' ), [ 'status' => 400 ] );
        }

        $config   = $this->storage->get_product_config( $product_id );
        $settings = $this->validator->settings();
        $product  = wc_get_product( $product_id );

        // Override mockup per variație dacă e setat.
        if ( $variation_id ) {
            $var_mockup_id = (int) get_post_meta( $variation_id, Design_Storage::META_MOCKUP, true );
            if ( $var_mockup_id ) {
                $config['mockup_url'] = (string) wp_get_attachment_image_url( $var_mockup_id, 'full' );
            }
        }

        return new WP_REST_Response( [
            'rest' => [
                'root'       => esc_url_raw( rest_url( PD_REST_NS . '/' ) ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'uploadPath' => 'upload',
                'designPath' => 'design',
            ],
            'product' => [
                'id'           => $product_id,
                'variation_id' => $variation_id,
                'mockup_url'   => $config['mockup_url'],
                'name'         => $product instanceof \WC_Product ? $product->get_name() : '',
                'price_html'   => $product instanceof \WC_Product ? $product->get_price_html() : '',
            ],
            'canvas' => [
                'width'  => (int) $settings['canvas_width'],
                'height' => (int) $settings['canvas_height'],
            ],
            'limits' => [
                'maxUploadMB' => (int) $settings['max_upload_size_mb'],
                'mimeTypes'   => $this->validator->allowed_mime_types(),
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
        ], 200 );
    }

    /**
     * GET /constructor/variations?product_id=X
     * Returnează atribute + variații disponibile pentru un produs variabil.
     */
    public function handle_variations( WP_REST_Request $request ) {
        nocache_headers();

        $product_id = (int) $request->get_param( 'product_id' );
        $product    = $product_id ? wc_get_product( $product_id ) : null;
        if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
            return new WP_Error( 'pd_not_variable', __( 'Produsul nu e variabil.', 'product-designer' ), [ 'status' => 400 ] );
        }

        // Atribute folosite în variații (size, color etc.).
        $attributes = [];
        foreach ( $product->get_variation_attributes() as $attr_name => $values ) {
            $taxonomy_name = wc_attribute_label( $attr_name, $product );
            $options       = [];
            foreach ( $values as $val ) {
                if ( taxonomy_exists( $attr_name ) ) {
                    $term = get_term_by( 'slug', $val, $attr_name );
                    $label = $term && ! is_wp_error( $term ) ? $term->name : $val;
                } else {
                    $label = $val;
                }
                $options[] = [ 'value' => (string) $val, 'label' => (string) $label ];
            }
            $attributes[] = [
                'name'    => $attr_name,
                'label'   => $taxonomy_name,
                'options' => $options,
            ];
        }

        // Lista de variații cu atribute + price + stock + mockup-ul (dacă există override per variație).
        $variations = [];
        foreach ( $product->get_available_variations() as $v ) {
            $vid          = (int) $v['variation_id'];
            $var_obj      = wc_get_product( $vid );
            $var_mockup_id = $var_obj instanceof \WC_Product ? (int) get_post_meta( $vid, Design_Storage::META_MOCKUP, true ) : 0;
            $var_mockup_url = $var_mockup_id ? (string) wp_get_attachment_image_url( $var_mockup_id, 'full' ) : '';

            $variations[] = [
                'id'              => $vid,
                'attributes'      => $v['attributes'] ?? [],
                'price_html'      => $v['price_html']  ?? '',
                'is_in_stock'     => ! empty( $v['is_in_stock'] ),
                'is_purchasable'  => ! empty( $v['is_purchasable'] ),
                'mockup_url'      => $var_mockup_url, // gol dacă nu e override; client folosește mockup parent
            ];
        }

        return new WP_REST_Response( [
            'attributes' => $attributes,
            'variations' => $variations,
        ], 200 );
    }

    private function count_templates_in_term( int $term_id ) : int {
        $query = new \WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => false,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => Templates::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
            'meta_query'     => [
                [
                    'key'   => Templates::META_IS_TEMPLATE,
                    'value' => 'yes',
                ],
            ],
        ] );
        return (int) $query->found_posts;
    }

    private function format_category( \WP_Term $term, int $count, int $order, int $icon_id ) : array {
        $icon_url = $icon_id ? (string) wp_get_attachment_image_url( $icon_id, 'medium' ) : '';
        return [
            'id'       => (int) $term->term_id,
            'name'     => $term->name,
            'slug'     => $term->slug,
            'icon_url' => $icon_url,
            'count'    => $count,
            'order'    => $order,
        ];
    }

    private function get_settings() : array {
        $defaults = [
            'enabled'    => true,
            'slug'       => PD_CONSTRUCTOR_SLUG,
            'title'      => __( 'Constructor produse', 'product-designer' ),
            'categories' => [],
        ];
        $stored = get_option( 'pd_constructor_settings', [] );
        return wp_parse_args( is_array( $stored ) ? $stored : [], $defaults );
    }
}
