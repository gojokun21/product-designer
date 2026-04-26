<?php
/**
 * Templates layer — produse WC marcate `_pd_is_template = yes` cu taxonomia
 * proprie `pd_template_cat`. Adminul le gestionează dintr-o pagină dedicată
 * sub `Product Designer → Templates`. Sunt ascunse de pe shop & din lista
 * obișnuită de produse.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Templates {

    public const META_IS_TEMPLATE = '_pd_is_template';
    public const TAXONOMY         = 'pd_template_cat';

    public function register() : void {
        add_action( 'init', [ $this, 'register_taxonomy' ], 5 );

        // Hide templates din shop frontend + lista admin standard de produse.
        add_action( 'pre_get_posts', [ $this, 'hide_templates_from_shop' ] );
        add_action( 'pre_get_posts', [ $this, 'hide_templates_from_admin_list' ] );

        // Marker la list table-ul de produse: badge „Template".
        add_filter( 'manage_edit-product_columns',         [ $this, 'add_template_column' ] );
        add_action( 'manage_product_posts_custom_column',  [ $this, 'render_template_column' ], 10, 2 );
    }

    public function register_taxonomy() : void {
        register_taxonomy( self::TAXONOMY, 'product', [
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => false, // afișăm separat pe admin list-ul nostru
            'show_in_menu'      => false, // legată doar de pagina noastră
            'show_in_nav_menus' => false,
            'show_in_rest'      => false,
            'rewrite'           => false,
            'query_var'         => false,
            'labels' => [
                'name'              => __( 'Categorii Constructor', 'product-designer' ),
                'singular_name'     => __( 'Categorie Constructor',  'product-designer' ),
                'menu_name'         => __( 'Categorii Constructor',  'product-designer' ),
                'all_items'         => __( 'Toate categoriile',      'product-designer' ),
                'edit_item'         => __( 'Editează categoria',     'product-designer' ),
                'view_item'         => __( 'Vizualizează categoria', 'product-designer' ),
                'update_item'       => __( 'Actualizează categoria', 'product-designer' ),
                'add_new_item'      => __( 'Adaugă categorie nouă',  'product-designer' ),
                'new_item_name'     => __( 'Nume categorie nouă',    'product-designer' ),
                'search_items'      => __( 'Caută categorii',        'product-designer' ),
                'not_found'         => __( 'Nicio categorie găsită', 'product-designer' ),
            ],
            'capabilities' => [
                'manage_terms' => 'manage_woocommerce',
                'edit_terms'   => 'manage_woocommerce',
                'delete_terms' => 'manage_woocommerce',
                'assign_terms' => 'manage_woocommerce',
            ],
        ] );
    }

    /**
     * Ascunde template-urile din shop, search, archive, taxonomy queries.
     */
    public function hide_templates_from_shop( \WP_Query $query ) : void {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }
        // Aplicăm doar pentru queries WC (shop, search, taxonomies de produs).
        $is_woo_query = ( function_exists( 'is_shop' ) && is_shop() )
            || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
            || $query->is_search()
            || $query->is_post_type_archive( 'product' );

        if ( ! $is_woo_query ) {
            return;
        }
        $this->merge_hide_meta_query( $query );
    }

    /**
     * Ascunde template-urile din lista standard admin de produse
     * (edit.php?post_type=product). Adminul are buton separat „Templates".
     */
    public function hide_templates_from_admin_list( \WP_Query $query ) : void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'edit-product' ) {
            return;
        }
        // Permitem un override explicit cu ?pd_show_templates=1 (ex. pentru debug).
        if ( ! empty( $_GET['pd_show_templates'] ) ) {
            return;
        }
        $this->merge_hide_meta_query( $query );
    }

    private function merge_hide_meta_query( \WP_Query $query ) : void {
        $existing = $query->get( 'meta_query' );
        $existing = is_array( $existing ) ? $existing : [];
        $existing[] = [
            'relation' => 'OR',
            [
                'key'     => self::META_IS_TEMPLATE,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => self::META_IS_TEMPLATE,
                'value'   => 'yes',
                'compare' => '!=',
            ],
        ];
        $query->set( 'meta_query', $existing );
    }

    public function add_template_column( array $columns ) : array {
        $columns['pd_template'] = __( 'Designer', 'product-designer' );
        return $columns;
    }

    public function render_template_column( string $column, int $post_id ) : void {
        if ( $column !== 'pd_template' ) {
            return;
        }
        if ( get_post_meta( $post_id, self::META_IS_TEMPLATE, true ) === 'yes' ) {
            echo '<span class="pd-badge pd-badge--template">' . esc_html__( 'Template', 'product-designer' ) . '</span>';
        } elseif ( get_post_meta( $post_id, Design_Storage::META_ENABLED, true ) === 'yes' ) {
            echo '<span class="pd-badge pd-badge--enabled">' . esc_html__( 'Personalizabil', 'product-designer' ) . '</span>';
        } else {
            echo '&mdash;';
        }
    }

    /**
     * Verifică dacă un produs e marcat ca template.
     */
    public static function is_template( int $product_id ) : bool {
        return get_post_meta( $product_id, self::META_IS_TEMPLATE, true ) === 'yes';
    }
}
