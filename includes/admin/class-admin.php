<?php
/**
 * General admin wiring: asset enqueue, settings page placeholder.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Admin {

    public function register() : void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    private static function asset_version( string $relative_path ) : string {
        $file = PD_PLUGIN_DIR . ltrim( $relative_path, '/' );
        $mtime = file_exists( $file ) ? filemtime( $file ) : false;
        return $mtime ? (string) $mtime : PD_VERSION;
    }

    public function enqueue( string $hook ) : void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        $is_product_edit = $screen && $screen->post_type === 'product' && in_array( $hook, [ 'post.php', 'post-new.php' ], true );
        $is_shop_order   = $screen && in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true );

        if ( ! $is_product_edit && ! $is_shop_order ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'pd-admin',
            PD_PLUGIN_URL . 'assets/css/admin.css',
            [],
            self::asset_version( 'assets/css/admin.css' )
        );

        wp_enqueue_script(
            'pd-admin',
            PD_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            self::asset_version( 'assets/js/admin.js' ),
            true
        );

        wp_localize_script( 'pd-admin', 'PDAdmin', [
            'i18n' => [
                'chooseMockup' => __( 'Selectează imaginea mockup', 'product-designer' ),
                'useMockup'    => __( 'Folosește această imagine', 'product-designer' ),
            ],
        ] );
    }
}
