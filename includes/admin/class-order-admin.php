<?php
/**
 * Renders design preview + download links inside the order edit screen.
 *
 * Works with both legacy post-based orders and HPOS (custom order tables).
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Admin;

use ProductDesigner\Core\Design_Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order_Admin {

    private Design_Storage $storage;

    public function __construct( Design_Storage $storage ) {
        $this->storage = $storage;
    }

    public function register() : void {
        add_action( 'woocommerce_after_order_itemmeta', [ $this, 'render_itemmeta' ], 10, 3 );
        add_action( 'admin_post_pd_download_design',    [ $this, 'handle_download' ] );
    }

    public function render_itemmeta( int $item_id, $item, $product ) : void {
        if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
            return;
        }

        $design_id   = (string) $item->get_meta( Design_Storage::ITEM_META_DESIGN_ID, true );
        $preview_url = (string) $item->get_meta( Design_Storage::ITEM_META_PREVIEW_URL, true );
        if ( ! $design_id ) {
            return;
        }

        $download_png = wp_nonce_url(
            admin_url( 'admin-post.php?action=pd_download_design&type=png&design=' . rawurlencode( $design_id ) ),
            'pd_download_' . $design_id
        );
        $download_json = wp_nonce_url(
            admin_url( 'admin-post.php?action=pd_download_design&type=json&design=' . rawurlencode( $design_id ) ),
            'pd_download_' . $design_id
        );
        ?>
        <div class="pd-order-design">
            <strong><?php esc_html_e( 'Design personalizat', 'product-designer' ); ?></strong>
            <?php if ( $preview_url ) : ?>
                <div class="pd-order-preview">
                    <img src="<?php echo esc_url( $preview_url ); ?>" alt="" style="max-width:180px;height:auto;border:1px solid #ddd;padding:4px;background:#fff;" />
                </div>
            <?php endif; ?>
            <p class="pd-order-links">
                <a class="button" href="<?php echo esc_url( $download_png ); ?>"><?php esc_html_e( 'Descarcă PNG', 'product-designer' ); ?></a>
                <a class="button" href="<?php echo esc_url( $download_json ); ?>"><?php esc_html_e( 'Descarcă JSON', 'product-designer' ); ?></a>
            </p>
            <code style="font-size:11px;opacity:.7;"><?php echo esc_html( $design_id ); ?></code>
        </div>
        <?php
    }

    public function handle_download() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permisiune insuficientă.', 'product-designer' ), '', [ 'response' => 403 ] );
        }

        $design_id = isset( $_GET['design'] ) ? sanitize_text_field( wp_unslash( $_GET['design'] ) ) : '';
        $type      = isset( $_GET['type'] )   ? sanitize_key( wp_unslash( $_GET['type'] ) )            : '';
        $nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! $design_id || ! wp_verify_nonce( $nonce, 'pd_download_' . $design_id ) ) {
            wp_die( esc_html__( 'Token invalid.', 'product-designer' ), '', [ 'response' => 403 ] );
        }
        if ( ! in_array( $type, [ 'png', 'json' ], true ) ) {
            wp_die( esc_html__( 'Tip necunoscut.', 'product-designer' ), '', [ 'response' => 400 ] );
        }

        $files = $this->storage->get_design_files( $design_id );
        $file  = $files[ $type ]['path'] ?? '';
        if ( ! $file || ! is_readable( $file ) ) {
            wp_die( esc_html__( 'Fișier inexistent.', 'product-designer' ), '', [ 'response' => 404 ] );
        }

        $mime     = $type === 'png' ? 'image/png' : 'application/json';
        $filename = $design_id . '.' . $type;

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $file ) );
        readfile( $file );
        exit;
    }
}
