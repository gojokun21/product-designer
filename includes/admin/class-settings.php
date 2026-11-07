<?php
/**
 * Settings page: retention period + manual cleanup trigger.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Admin;

use ProductDesigner\Core\Cleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Settings {

    public const MENU_SLUG  = 'pd-settings';
    public const OPTION_KEY = 'pd_settings';
    public const NONCE_RUN  = 'pd_run_cleanup';

    public function register() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_setting' ] );
        add_action( 'admin_post_pd_run_cleanup', [ $this, 'handle_run_now' ] );
    }

    public function register_menu() : void {
        add_submenu_page(
            Templates_Admin::MENU_SLUG,
            __( 'Setări', 'product-designer' ),
            __( 'Setări', 'product-designer' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function register_setting() : void {
        register_setting(
            'pd_settings_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => [],
            ]
        );
    }

    /**
     * Merges submitted fields onto the existing option so we don't clobber
     * keys we don't render in this UI (max_upload_size_mb, allowed_mime_types, etc.).
     */
    public function sanitize( $input ) : array {
        $current = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $current ) ) {
            $current = [];
        }
        if ( is_array( $input ) && isset( $input['retention_days'] ) ) {
            $days = (int) $input['retention_days'];
            $current['retention_days'] = max( 0, min( 365, $days ) );
        }
        return $current;
    }

    public function handle_run_now() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permisiune insuficientă.', 'product-designer' ) );
        }
        check_admin_referer( self::NONCE_RUN );

        do_action( Cleanup::CRON_HOOK );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'pd_cleanup_ran' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function render() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permisiune insuficientă.', 'product-designer' ) );
        }

        $option   = get_option( self::OPTION_KEY, [] );
        $days     = isset( $option['retention_days'] ) ? (int) $option['retention_days'] : 14;
        $last_run = get_option( Cleanup::LAST_RUN_OPTION, [] );
        $next_run = wp_next_scheduled( Cleanup::CRON_HOOK );
        $just_ran = isset( $_GET['pd_cleanup_ran'] );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Product Designer — Setări', 'product-designer' ); ?></h1>

            <?php if ( $just_ran ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Curățarea a rulat.', 'product-designer' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'pd_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="pd-retention-days"><?php esc_html_e( 'Zile retenție fișiere personalizare', 'product-designer' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="pd-retention-days"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[retention_days]"
                                value="<?php echo esc_attr( (string) $days ); ?>"
                                min="0"
                                max="365"
                                step="1"
                                class="small-text"
                            />
                            <p class="description">
                                <?php esc_html_e( 'Designurile și imaginile încărcate de clienți care nu au ajuns într-o comandă sau coș activ se șterg după acest număr de zile. Default: 14.', 'product-designer' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr/>

            <h2><?php esc_html_e( 'Status', 'product-designer' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Următoarea rulare programată', 'product-designer' ); ?></th>
                    <td>
                        <?php
                        if ( $next_run ) {
                            echo esc_html( wp_date( 'Y-m-d H:i', (int) $next_run ) );
                        } else {
                            esc_html_e( 'Neprogramat', 'product-designer' );
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Ultima rulare', 'product-designer' ); ?></th>
                    <td>
                        <?php if ( ! empty( $last_run['timestamp'] ) ) : ?>
                            <p>
                                <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $last_run['timestamp'] ) ); ?>
                            </p>
                            <p>
                                <?php
                                printf(
                                    /* translators: 1: designs deleted, 2: attachments deleted, 3: designs preserved, 4: attachments preserved */
                                    esc_html__( 'Șterse: %1$d fișiere design + %2$d atașamente. Păstrate: %3$d designuri + %4$d atașamente.', 'product-designer' ),
                                    (int) ( $last_run['deleted_designs'] ?? 0 ),
                                    (int) ( $last_run['deleted_attachments'] ?? 0 ),
                                    (int) ( $last_run['preserved_designs'] ?? 0 ),
                                    (int) ( $last_run['preserved_attachments'] ?? 0 )
                                );
                                ?>
                            </p>
                        <?php else : ?>
                            <em><?php esc_html_e( 'Nu a rulat încă.', 'product-designer' ); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="pd_run_cleanup" />
                <?php wp_nonce_field( self::NONCE_RUN ); ?>
                <?php submit_button( __( 'Rulează curățarea acum', 'product-designer' ), 'secondary', 'pd-run-cleanup', false ); ?>
            </form>
        </div>
        <?php
    }
}
