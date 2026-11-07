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

    public const OPTION_MIGRATIONS = 'pd_migrations';

    public static function activate() : void {
        self::ensure_upload_directory();

        if ( get_option( 'pd_settings' ) === false ) {
            add_option( 'pd_settings', [
                'max_upload_size_mb' => 5,
                'allowed_mime_types' => [ 'image/png', 'image/jpeg', 'image/webp' ],
                'canvas_width'       => 600,
                'canvas_height'      => 700,
                'retention_days'     => 14,
            ] );
        } else {
            // Existing install missing the new key — top it up without clobbering other fields.
            $current = get_option( 'pd_settings', [] );
            if ( is_array( $current ) && ! array_key_exists( 'retention_days', $current ) ) {
                $current['retention_days'] = 14;
                update_option( 'pd_settings', $current );
            }
        }

        // Auto-creare pagină Constructor.
        \ProductDesigner\Core\Page_Installer::install();

        // Daily GC for abandoned personalization uploads.
        if ( ! wp_next_scheduled( \ProductDesigner\Core\Cleanup::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', \ProductDesigner\Core\Cleanup::CRON_HOOK );
        }

        update_option( 'pd_db_version', PD_VERSION );

        self::maybe_upgrade();
    }

    /**
     * Migrări idempotente rulate și pe `admin_init` — activation hook-ul nu
     * re-firează la simplul update de cod, iar starea asta trebuie reparată și
     * pe instalările existente.
     */
    public static function maybe_upgrade() : void {
        $done = get_option( self::OPTION_MIGRATIONS, [] );
        $done = is_array( $done ) ? $done : [];

        if ( ! in_array( 'template_enabled_backfill', $done, true ) ) {
            self::backfill_template_enabled();
            $done[] = 'template_enabled_backfill';
            update_option( self::OPTION_MIGRATIONS, array_values( array_unique( $done ) ) );
        }

        // Print size per side, priced by the plugin instead of a variation
        // attribute (2026-11). Retried on a later request when it can't run yet.
        if ( ! in_array( 'print_sizes_per_side', $done, true ) && \ProductDesigner\Core\Print_Sizes_Migration::run() ) {
            $done[] = 'print_sizes_per_side';
            update_option( self::OPTION_MIGRATIONS, array_values( array_unique( $done ) ) );
        }
    }

    /**
     * Un template fără `_pd_enabled` e invizibil pentru designer: REST-ul
     * `/constructor/product-config` întoarce 400, deci pasul 3 al wizard-ului
     * nu pornește. Meta se setează la crearea template-ului din admin, dar
     * template-urile mai vechi (sau importate/duplicate) pot să nu o aibă.
     *
     * Setăm doar acolo unde meta lipsește cu totul — un `no` explicit înseamnă
     * că adminul a dezactivat designerul intenționat.
     */
    private static function backfill_template_enabled() : void {
        $query = new \WP_Query( [
            'post_type'        => 'product',
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'lang'             => '', // Toate limbile (no-op fără Polylang).
            'meta_query'       => [
                [
                    'key'   => \ProductDesigner\Core\Templates::META_IS_TEMPLATE,
                    'value' => 'yes',
                ],
            ],
        ] );

        foreach ( $query->posts as $product_id ) {
            $product_id = (int) $product_id;
            if ( ! metadata_exists( 'post', $product_id, \ProductDesigner\Core\Design_Storage::META_ENABLED ) ) {
                update_post_meta( $product_id, \ProductDesigner\Core\Design_Storage::META_ENABLED, 'yes' );
            }
        }
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
