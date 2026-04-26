<?php
/**
 * Main plugin bootstrap.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner;

use ProductDesigner\Admin\Admin;
use ProductDesigner\Admin\Product_Metabox;
use ProductDesigner\Admin\Order_Admin;
use ProductDesigner\Admin\Templates_Admin;
use ProductDesigner\Api\Rest_Api;
use ProductDesigner\Api\Rest_Constructor;
use ProductDesigner\Core\Design_Storage;
use ProductDesigner\Core\Image_Handler;
use ProductDesigner\Core\Page_Installer;
use ProductDesigner\Core\Templates;
use ProductDesigner\Core\Validator;
use ProductDesigner\Frontend\Frontend;
use ProductDesigner\Frontend\Constructor_Shortcode;
use ProductDesigner\Woocommerce\Cart;
use ProductDesigner\Woocommerce\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    private static ?Plugin $instance = null;

    private Design_Storage $storage;
    private Image_Handler  $images;
    private Validator      $validator;

    public static function instance() : Plugin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->validator = new Validator();
        $this->images    = new Image_Handler( $this->validator );
        $this->storage   = new Design_Storage( $this->images );
    }

    public function boot() : void {
        load_plugin_textdomain( 'product-designer', false, dirname( PD_PLUGIN_BASE ) . '/languages' );

        // Asigură pagina Constructor:
        //  - upgrade-uri (plugin deja activ, codul actualizat — activation hook nu re-firează)
        //  - recovery dacă adminul a șters accidental pagina
        add_action( 'admin_init', static function () {
            $page_id = (int) get_option( Page_Installer::OPTION_PAGE_ID );
            if ( ! $page_id || ! Page_Installer::page_is_valid( $page_id ) ) {
                Page_Installer::install();
            }
        } );

        ( new Templates() )->register();

        ( new Product_Metabox() )->register();
        ( new Templates_Admin() )->register();
        ( new Order_Admin( $this->storage ) )->register();
        ( new Admin() )->register();

        ( new Frontend( $this->storage ) )->register();
        ( new Constructor_Shortcode() )->register();

        ( new Cart( $this->storage ) )->register();
        ( new Order( $this->storage ) )->register();

        ( new Rest_Api( $this->storage, $this->images, $this->validator ) )->register();
        ( new Rest_Constructor( $this->storage, $this->validator ) )->register();
    }

    public function storage()   : Design_Storage { return $this->storage; }
    public function images()    : Image_Handler  { return $this->images; }
    public function validator() : Validator      { return $this->validator; }
}
