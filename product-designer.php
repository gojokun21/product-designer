<?php
/**
 * Plugin Name: Product Designer for WooCommerce
 * Plugin URI:  https://example.com/product-designer
 * Description: Editor vizual (Fabric.js) pentru personalizarea produselor WooCommerce - haine, textile, print-on-demand.
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: product-designer
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package ProductDesigner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PD_VERSION',      '1.0.0' );
define( 'PD_PLUGIN_FILE',  __FILE__ );
define( 'PD_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'PD_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'PD_PLUGIN_BASE',  plugin_basename( __FILE__ ) );
define( 'PD_REST_NS',      'product-designer/v1' );
define( 'PD_UPLOAD_SUBDIR','product-designer' );
define( 'PD_CONSTRUCTOR_SLUG',      'constructor' );
define( 'PD_CONSTRUCTOR_SHORTCODE', 'pd_constructor' );

require_once PD_PLUGIN_DIR . 'includes/class-autoloader.php';
\ProductDesigner\Autoloader::register();

register_activation_hook(   __FILE__, [ \ProductDesigner\Activator::class,   'activate'   ] );
register_deactivation_hook( __FILE__, [ \ProductDesigner\Deactivator::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'Product Designer necesită WooCommerce activ.', 'product-designer' );
            echo '</p></div>';
        } );
        return;
    }
    \ProductDesigner\Plugin::instance()->boot();
} );

// HPOS compatibility declaration.
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );
