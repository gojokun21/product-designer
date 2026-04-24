<?php
/**
 * Activation logic.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Activator {

    public static function activate() : void {
        self::ensure_upload_directory();

        if ( get_option( 'pd_settings' ) === false ) {
            add_option( 'pd_settings', [
                'max_upload_size_mb' => 5,
                'allowed_mime_types' => [ 'image/png', 'image/jpeg', 'image/webp' ],
                'canvas_width'       => 600,
                'canvas_height'      => 700,
            ] );
        }

        update_option( 'pd_db_version', PD_VERSION );
    }

    private static function ensure_upload_directory() : void {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . PD_UPLOAD_SUBDIR;

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Prevent directory listing.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Options -Indexes\n" );
        }
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }
}
