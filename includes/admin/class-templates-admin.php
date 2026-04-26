<?php
/**
 * Admin UI pentru Templates: meniu propriu sub `Product Designer` cu listă
 * de template-uri (produse WC marcate `_pd_is_template = yes`) și buton
 * „Adaugă template nou" care deschide editorul WC cu defaults pre-set.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Admin;

use ProductDesigner\Core\Templates;
use ProductDesigner\Core\Design_Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Templates_Admin {

    public const MENU_SLUG = 'pd-templates';
    public const QUERY_FLAG_NEW_TEMPLATE = 'pd_new_template';

    public function register() : void {
        add_action( 'admin_menu',          [ $this, 'register_menu' ] );

        // Pre-fill meta când admin click „Adaugă template nou" și deschide
        // editorul WC standard cu un query param.
        add_action( 'admin_init',          [ $this, 'maybe_pre_fill_new_template' ] );

        // Salvează _pd_is_template + categoria template pe save_post.
        add_action( 'save_post_product',   [ $this, 'save_template_meta' ], 20, 2 );

        // Adaugă un metabox pe edit produs pentru template-uri (categorie + flag).
        add_action( 'add_meta_boxes',      [ $this, 'add_template_metabox' ] );

        // CSS mic pentru badge-uri și pagina template-urilor.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_css' ] );
    }

    public function register_menu() : void {
        $cap = 'manage_woocommerce';

        // Toplevel „Product Designer".
        add_menu_page(
            __( 'Product Designer', 'product-designer' ),
            __( 'Product Designer', 'product-designer' ),
            $cap,
            self::MENU_SLUG,
            [ $this, 'render_templates_page' ],
            'dashicons-art',
            56
        );

        // Submeniu „Templates" (același target ca toplevel — convenție WP).
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Templates', 'product-designer' ),
            __( 'Templates', 'product-designer' ),
            $cap,
            self::MENU_SLUG,
            [ $this, 'render_templates_page' ]
        );

        // Submeniu „Categorii constructor" — duce la edit-tags.php pentru taxonomia noastră.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Categorii constructor', 'product-designer' ),
            __( 'Categorii', 'product-designer' ),
            $cap,
            'edit-tags.php?taxonomy=' . Templates::TAXONOMY . '&post_type=product'
        );
    }

    public function render_templates_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permisiune insuficientă.', 'product-designer' ) );
        }

        $new_url = admin_url( 'post-new.php?post_type=product&' . self::QUERY_FLAG_NEW_TEMPLATE . '=1' );

        // Query pentru template-uri.
        $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $cat   = isset( $_GET['cat'] )   ? (int) $_GET['cat'] : 0;

        $args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => 30,
            'paged'          => $paged,
            'meta_query'     => [
                [ 'key' => Templates::META_IS_TEMPLATE, 'value' => 'yes' ],
            ],
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];
        if ( $cat ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => Templates::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $cat,
                ],
            ];
        }

        $query     = new \WP_Query( $args );
        $all_terms = get_terms( [ 'taxonomy' => Templates::TAXONOMY, 'hide_empty' => false ] );
        ?>
        <div class="wrap pd-templates-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Templates Constructor', 'product-designer' ); ?></h1>
            <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">
                <?php esc_html_e( 'Adaugă template nou', 'product-designer' ); ?>
            </a>
            <hr class="wp-header-end" />

            <p class="description">
                <?php esc_html_e( 'Template-urile sunt produse personalizabile afișate doar în pagina Constructor. Ele NU apar în shop-ul obișnuit și NU sunt vizibile în lista standard de produse WooCommerce.', 'product-designer' ); ?>
            </p>

            <?php if ( ! empty( $all_terms ) && ! is_wp_error( $all_terms ) ) : ?>
                <ul class="subsubsub">
                    <li>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="<?php echo $cat === 0 ? 'current' : ''; ?>">
                            <?php esc_html_e( 'Toate', 'product-designer' ); ?>
                        </a> |
                    </li>
                    <?php $i = 0; $total = count( $all_terms ); foreach ( $all_terms as $term ) : $i++; ?>
                        <li>
                            <a href="<?php echo esc_url( add_query_arg( 'cat', $term->term_id, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) ); ?>"
                               class="<?php echo $cat === (int) $term->term_id ? 'current' : ''; ?>">
                                <?php echo esc_html( $term->name ); ?>
                                <span class="count">(<?php echo (int) $term->count; ?>)</span>
                            </a><?php echo $i < $total ? ' |' : ''; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <br class="clear" />
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped pd-templates-table">
                <thead>
                    <tr>
                        <th class="column-thumb" style="width:80px;"><?php esc_html_e( 'Imagine', 'product-designer' ); ?></th>
                        <th class="column-title"><?php esc_html_e( 'Nume', 'product-designer' ); ?></th>
                        <th><?php esc_html_e( 'Tip', 'product-designer' ); ?></th>
                        <th><?php esc_html_e( 'Preț', 'product-designer' ); ?></th>
                        <th><?php esc_html_e( 'Categorie', 'product-designer' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'product-designer' ); ?></th>
                        <th><?php esc_html_e( 'Mockup', 'product-designer' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( ! $query->have_posts() ) : ?>
                    <tr>
                        <td colspan="7">
                            <p style="text-align:center; padding:30px 10px;">
                                <?php esc_html_e( 'Niciun template încă. Folosește butonul „Adaugă template nou" de mai sus.', 'product-designer' ); ?>
                            </p>
                        </td>
                    </tr>
                <?php else : while ( $query->have_posts() ) : $query->the_post();
                    $product = wc_get_product( get_the_ID() );
                    if ( ! $product ) { continue; }

                    $edit_link  = get_edit_post_link();
                    $thumb_id   = (int) $product->get_image_id();
                    $thumb_url  = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
                    $mockup_id  = (int) get_post_meta( get_the_ID(), Design_Storage::META_MOCKUP, true );
                    $mockup_url = $mockup_id ? (string) wp_get_attachment_image_url( $mockup_id, 'thumbnail' ) : '';
                    $terms      = get_the_terms( get_the_ID(), Templates::TAXONOMY );
                    $term_names = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : [];
                    ?>
                    <tr>
                        <td>
                            <?php if ( $thumb_url ) : ?>
                                <img src="<?php echo esc_url( $thumb_url ); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:3px;" alt="" />
                            <?php else : ?>
                                <span class="dashicons dashicons-format-image" style="font-size:48px;color:#ccc;"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><a href="<?php echo esc_url( $edit_link ); ?>"><?php the_title(); ?></a></strong>
                            <div class="row-actions">
                                <span class="edit"><a href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Editează', 'product-designer' ); ?></a> | </span>
                                <span class="trash"><a href="<?php echo esc_url( get_delete_post_link( get_the_ID() ) ); ?>" class="submitdelete"><?php esc_html_e( 'Șterge', 'product-designer' ); ?></a></span>
                            </div>
                        </td>
                        <td><?php echo esc_html( ucfirst( $product->get_type() ) ); ?></td>
                        <td><?php echo wp_kses_post( $product->get_price_html() ); ?></td>
                        <td><?php echo esc_html( implode( ', ', $term_names ) ); ?></td>
                        <td><?php echo esc_html( get_post_status_object( get_post_status() )->label ?? get_post_status() ); ?></td>
                        <td>
                            <?php if ( $mockup_url ) : ?>
                                <img src="<?php echo esc_url( $mockup_url ); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:3px;border:1px solid #ddd;" alt="" />
                            <?php else : ?>
                                <span style="color:#a00;">⚠ <?php esc_html_e( 'Lipsă', 'product-designer' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; wp_reset_postdata(); endif; ?>
                </tbody>
            </table>

            <?php
            // Paginare simplă.
            $total_pages = (int) $query->max_num_pages;
            if ( $total_pages > 1 ) :
                $base = remove_query_arg( 'paged', admin_url( 'admin.php?page=' . self::MENU_SLUG . ( $cat ? '&cat=' . $cat : '' ) ) );
                ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post( paginate_links( [
                            'base'    => add_query_arg( 'paged', '%#%', $base ),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $total_pages,
                        ] ) );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Pre-fill meta și valori default când admin click „Adaugă template nou".
     * Hook-uim post_name + meta default după ce WP creează auto-draft.
     */
    public function maybe_pre_fill_new_template() : void {
        global $pagenow;
        if ( $pagenow !== 'post-new.php' ) { return; }
        if ( ! isset( $_GET['post_type'] ) || $_GET['post_type'] !== 'product' ) { return; }
        if ( empty( $_GET[ self::QUERY_FLAG_NEW_TEMPLATE ] ) ) { return; }

        // Pre-fill default values via auto-draft hook.
        add_action( 'wp_insert_post', static function ( $post_id, $post ) {
            if ( $post->post_type !== 'product' || $post->post_status !== 'auto-draft' ) {
                return;
            }
            update_post_meta( $post_id, Templates::META_IS_TEMPLATE, 'yes' );
            update_post_meta( $post_id, Design_Storage::META_ENABLED, 'yes' );
            update_post_meta( $post_id, '_visibility', 'hidden' );
            // catalog_visibility WC term.
            wp_set_object_terms( $post_id, 'exclude-from-catalog', 'product_visibility', true );
            wp_set_object_terms( $post_id, 'exclude-from-search', 'product_visibility', true );
        }, 10, 2 );

        // Notice prietenos.
        add_action( 'admin_notices', static function () {
            ?>
            <div class="notice notice-info">
                <p><strong><?php esc_html_e( 'Template Constructor', 'product-designer' ); ?>:</strong>
                <?php esc_html_e( 'Acest produs e configurat ca template. Va apărea în pagina Constructor și va fi ascuns din shop. Configurează: nume, preț, mockup (tab Product Designer), categorie (Categorii constructor), variații dacă vrei mărimi/culori.', 'product-designer' ); ?></p>
            </div>
            <?php
        } );
    }

    /**
     * Salvează checkbox + categoria din metabox-ul nostru, apoi forțează
     * template-urile să rămână ascunse din catalog.
     */
    public function save_template_meta( int $post_id, \WP_Post $post ) : void {
        if ( wp_is_post_revision( $post_id ) ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        // Verifică nonce-ul nostru DOAR dacă a fost trimis (poate veni și auto-draft).
        if ( isset( $_POST['pd_template_nonce'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_POST['pd_template_nonce'] ) );
            if ( ! wp_verify_nonce( $nonce, 'pd_template_metabox' ) ) {
                return;
            }
            // Checkbox „is template".
            $is_template = isset( $_POST['pd_is_template'] ) && $_POST['pd_is_template'] === 'yes';
            update_post_meta( $post_id, Templates::META_IS_TEMPLATE, $is_template ? 'yes' : 'no' );

            // Categorii pd_template_cat (multiselect).
            if ( isset( $_POST['pd_template_cat'] ) && is_array( $_POST['pd_template_cat'] ) ) {
                $cat_ids = array_map( 'absint', wp_unslash( $_POST['pd_template_cat'] ) );
                $cat_ids = array_filter( $cat_ids );
                wp_set_object_terms( $post_id, $cat_ids, Templates::TAXONOMY, false );
            }
        }

        // Forțează ascundere din catalog + search dacă e template.
        if ( get_post_meta( $post_id, Templates::META_IS_TEMPLATE, true ) === 'yes' ) {
            wp_set_object_terms( $post_id, [ 'exclude-from-catalog', 'exclude-from-search' ], 'product_visibility', false );
        }
    }

    public function add_template_metabox() : void {
        add_meta_box(
            'pd-template-info',
            __( 'Designer Template', 'product-designer' ),
            [ $this, 'render_template_metabox' ],
            'product',
            'side',
            'high'
        );
    }

    public function render_template_metabox( \WP_Post $post ) : void {
        $is_template = get_post_meta( $post->ID, Templates::META_IS_TEMPLATE, true ) === 'yes';
        wp_nonce_field( 'pd_template_metabox', 'pd_template_nonce' );
        ?>
        <p>
            <label>
                <input type="checkbox" name="pd_is_template" value="yes" <?php checked( $is_template, true ); ?> />
                <strong><?php esc_html_e( 'Marchează ca template Constructor', 'product-designer' ); ?></strong>
            </label>
        </p>
        <p class="description" style="margin:0 0 8px;">
            <?php esc_html_e( 'Bifează ca produsul să apară în pagina Constructor și să fie ascuns din shop-ul normal.', 'product-designer' ); ?>
        </p>
        <?php
        // Permite editarea categoriei direct aici pentru rapiditate.
        $terms = get_terms( [ 'taxonomy' => Templates::TAXONOMY, 'hide_empty' => false ] );
        if ( ! is_wp_error( $terms ) && $terms ) {
            $current = wp_get_object_terms( $post->ID, Templates::TAXONOMY, [ 'fields' => 'ids' ] );
            $current = is_array( $current ) ? $current : [];
            ?>
            <p><strong><?php esc_html_e( 'Categorie Constructor', 'product-designer' ); ?></strong></p>
            <p>
                <select name="pd_template_cat[]" multiple style="width:100%; min-height:80px;">
                    <?php foreach ( $terms as $term ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>" <?php selected( in_array( (int) $term->term_id, $current, true ), true ); ?>>
                            <?php echo esc_html( $term->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <?php
        } else {
            ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to taxonomy edit page */
                    esc_html__( 'Niciuna categorie definită încă. %s', 'product-designer' ),
                    '<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . Templates::TAXONOMY . '&post_type=product' ) ) . '">' . esc_html__( 'Adaugă categorii', 'product-designer' ) . '</a>'
                );
                ?>
            </p>
            <?php
        }
    }

    public function enqueue_admin_css( string $hook ) : void {
        // Doar pe paginile noastre + admin product list / edit.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $on_template_page = isset( $_GET['page'] ) && $_GET['page'] === self::MENU_SLUG;
        $on_product_admin = $screen && in_array( $screen->id, [ 'edit-product', 'product' ], true );
        if ( ! $on_template_page && ! $on_product_admin ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', '
            .pd-badge { display:inline-block; padding:2px 7px; font-size:11px; font-weight:600; border-radius:3px; }
            .pd-badge--template { background:#1a73e8; color:#fff; }
            .pd-badge--enabled  { background:#e8f0fe; color:#1a73e8; }
            .pd-templates-wrap .pd-templates-table img { vertical-align:middle; }
        ' );
    }
}
