<?php
/**
 * Garbage-collects abandoned personalization uploads.
 *
 *   - Design files (pd-<id>.json + pd-<id>-{front,back}.png) on disk
 *   - WP attachments uploaded through the designer (tagged with _pd_attachment)
 *
 * Anything still referenced by a placed order OR an active cart is preserved.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cleanup {

    public const CRON_HOOK            = 'pd_cleanup_designs';
    public const ATTACHMENT_FLAG_META = '_pd_attachment';
    public const LAST_RUN_OPTION      = 'pd_cleanup_last_run';

    private Design_Storage $storage;
    private Image_Handler  $images;
    private Validator      $validator;

    public function __construct( Design_Storage $storage, Image_Handler $images, Validator $validator ) {
        $this->storage   = $storage;
        $this->images    = $images;
        $this->validator = $validator;
    }

    public function register() : void {
        add_action( 'init',           [ $this, 'maybe_schedule' ] );
        add_action( self::CRON_HOOK,  [ $this, 'run' ] );
    }

    /**
     * Defensive scheduler — covers code-update upgrades where the activation hook
     * does not refire. Same pattern as Page_Installer::install() on admin_init.
     */
    public function maybe_schedule() : void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Entry point for both cron and "Run now" admin button.
     *
     * @return array{deleted_designs:int,deleted_attachments:int,preserved_designs:int,preserved_attachments:int}
     */
    public function run() : array {
        $retention_days = $this->retention_days();
        // retention_days = 0 means "delete everything older than now" — still valid for manual runs.
        $cutoff = time() - ( $retention_days * DAY_IN_SECONDS );

        $preserved_design_ids     = $this->collect_preserved_design_ids();
        $preserved_attachment_ids = $this->collect_preserved_attachment_ids( $preserved_design_ids );

        $deleted_designs     = $this->delete_expired_design_files( $cutoff, $preserved_design_ids );
        $deleted_attachments = $this->delete_expired_attachments( $cutoff, $preserved_attachment_ids );

        $stats = [
            'timestamp'             => time(),
            'retention_days'        => $retention_days,
            'deleted_designs'       => $deleted_designs,
            'deleted_attachments'   => $deleted_attachments,
            'preserved_designs'     => count( $preserved_design_ids ),
            'preserved_attachments' => count( $preserved_attachment_ids ),
        ];
        update_option( self::LAST_RUN_OPTION, $stats, false );

        $this->log( sprintf(
            'cleanup run: deleted %d design files, %d attachments. Preserved %d designs, %d attachments. retention=%d days.',
            $deleted_designs, $deleted_attachments, count( $preserved_design_ids ), count( $preserved_attachment_ids ), $retention_days
        ) );

        return $stats;
    }

    private function retention_days() : int {
        $settings = $this->validator->settings();
        $days     = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : 14;
        return max( 0, min( 365, $days ) );
    }

    /**
     * Designs referenced anywhere we must NOT delete:
     *   - any WC order item meta `_pd_design_id` (all statuses, forever)
     *   - any non-expired WC session whose cart carries pd_design.design_id
     *   - any persistent cart on a registered user
     */
    private function collect_preserved_design_ids() : array {
        global $wpdb;

        $ids = [];

        // 1. Order item meta — covers HPOS too (woocommerce_order_itemmeta is the same table).
        $order_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        // The table exists only when WooCommerce is active, which is guaranteed by product-designer.php.
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$order_table} WHERE meta_key = %s AND meta_value <> ''",
            Design_Storage::ITEM_META_DESIGN_ID
        ) );
        foreach ( (array) $rows as $design_id ) {
            $ids[ (string) $design_id ] = true;
        }

        // 2. WC sessions (guest + logged-in carts not yet checked out).
        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $session_rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT session_value FROM {$sessions_table} WHERE session_expiry > %d",
            time()
        ) );
        foreach ( (array) $session_rows as $blob ) {
            foreach ( $this->extract_design_ids_from_session_blob( (string) $blob ) as $design_id ) {
                $ids[ $design_id ] = true;
            }
        }

        // 3. Persistent carts (logged-in users — survives session expiry).
        $persistent_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
        $persistent_rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            $persistent_key
        ) );
        foreach ( (array) $persistent_rows as $blob ) {
            $cart = maybe_unserialize( $blob );
            if ( is_array( $cart ) && isset( $cart['cart'] ) && is_array( $cart['cart'] ) ) {
                foreach ( $cart['cart'] as $item ) {
                    if ( ! empty( $item['pd_design']['design_id'] ) ) {
                        $ids[ (string) $item['pd_design']['design_id'] ] = true;
                    }
                }
            }
        }

        return array_keys( $ids );
    }

    /**
     * WC stores session_value as a serialized assoc array. The `cart` entry inside
     * is itself a maybe_serialize / JSON-encoded representation of the cart contents
     * (varies by WC version). We try both.
     */
    private function extract_design_ids_from_session_blob( string $blob ) : array {
        $out = [];
        $session = maybe_unserialize( $blob );
        if ( ! is_array( $session ) || empty( $session['cart'] ) ) {
            return $out;
        }
        $cart = $session['cart'];
        if ( is_string( $cart ) ) {
            $maybe = maybe_unserialize( $cart );
            if ( ! is_array( $maybe ) ) {
                $maybe = json_decode( $cart, true );
            }
            $cart = is_array( $maybe ) ? $maybe : [];
        }
        if ( ! is_array( $cart ) ) {
            return $out;
        }
        foreach ( $cart as $item ) {
            if ( is_array( $item ) && ! empty( $item['pd_design']['design_id'] ) ) {
                $out[] = (string) $item['pd_design']['design_id'];
            }
        }
        return $out;
    }

    /**
     * Attachments to preserve: every image referenced by a preserved design.
     * Resolves Fabric image src URLs back to WP attachment IDs.
     */
    private function collect_preserved_attachment_ids( array $preserved_design_ids ) : array {
        $ids = [];
        foreach ( $preserved_design_ids as $design_id ) {
            $json = $this->storage->get_design_json( (string) $design_id );
            if ( ! is_array( $json ) ) {
                continue;
            }
            foreach ( $this->extract_image_srcs( $json ) as $src ) {
                $attachment_id = attachment_url_to_postid( $src );
                if ( $attachment_id > 0 ) {
                    $ids[ $attachment_id ] = true;
                }
            }
        }
        return array_keys( $ids );
    }

    /**
     * Walks a Design_Storage v2 payload (`{front:{objects:[...]}, back:{...}}`)
     * and yields the `src` of every image object found. Also handles v1 root.
     */
    private function extract_image_srcs( array $json ) : array {
        $srcs = [];
        $sides = [];
        if ( isset( $json['front'] ) && is_array( $json['front'] ) ) {
            $sides[] = $json['front'];
        }
        if ( isset( $json['back'] ) && is_array( $json['back'] ) ) {
            $sides[] = $json['back'];
        }
        if ( empty( $sides ) && isset( $json['objects'] ) ) {
            $sides[] = $json;
        }
        foreach ( $sides as $canvas ) {
            $objects = $canvas['objects'] ?? [];
            if ( ! is_array( $objects ) ) {
                continue;
            }
            foreach ( $objects as $obj ) {
                if ( is_array( $obj ) && ! empty( $obj['src'] ) && is_string( $obj['src'] ) && strpos( $obj['src'], 'data:' ) !== 0 ) {
                    $srcs[] = $obj['src'];
                }
            }
        }
        return $srcs;
    }

    /**
     * Scans the product-designer upload subdir and unlinks expired files.
     *
     * @return int Count of files deleted.
     */
    private function delete_expired_design_files( int $cutoff, array $preserved_design_ids ) : int {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . PD_UPLOAD_SUBDIR;
        if ( ! is_dir( $dir ) ) {
            return 0;
        }

        $preserved_set = array_flip( $preserved_design_ids );
        $deleted = 0;

        $files = glob( trailingslashit( $dir ) . 'pd-*.{json,png}', GLOB_BRACE );
        if ( ! is_array( $files ) ) {
            return 0;
        }

        foreach ( $files as $path ) {
            $name = basename( $path );
            if ( ! preg_match( '/^pd-(.+?)(?:-(?:front|back))?\.(?:json|png)$/', $name, $m ) ) {
                continue;
            }
            $design_id = $m[1];
            if ( isset( $preserved_set[ $design_id ] ) ) {
                continue;
            }
            $mtime = @filemtime( $path );
            if ( $mtime === false || $mtime >= $cutoff ) {
                continue;
            }
            if ( @unlink( $path ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Deletes WP attachments that were uploaded via the designer (tagged with
     * _pd_attachment) and are older than the cutoff and not preserved.
     *
     * @return int Count of attachments deleted.
     */
    private function delete_expired_attachments( int $cutoff, array $preserved_attachment_ids ) : int {
        $deleted  = 0;
        $per_page = 200;

        // Always query page 1: deletions shrink the set, so the next iteration
        // sees the next batch of unprocessed records without skipping anything.
        while ( true ) {
            $args = [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $per_page,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                // Media e traductibila pe site-urile Polylang: fara asta GC-ul
                // ar vedea doar atasamentele dintr-o limba si ar lasa restul
                // sa se acumuleze la nesfarsit. No-op fara Polylang.
                'lang'           => '',
                'date_query'     => [ [ 'before' => gmdate( 'Y-m-d H:i:s', $cutoff ), 'inclusive' => true ] ],
                'meta_query'     => [
                    [
                        'key'     => self::ATTACHMENT_FLAG_META,
                        'compare' => 'EXISTS',
                    ],
                ],
            ];
            if ( ! empty( $preserved_attachment_ids ) ) {
                $args['post__not_in'] = $preserved_attachment_ids;
            }

            $query = new \WP_Query( $args );
            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $attachment_id ) {
                if ( wp_delete_attachment( (int) $attachment_id, true ) ) {
                    $deleted++;
                }
            }

            if ( count( $query->posts ) < $per_page ) {
                break;
            }
        }

        return $deleted;
    }

    private function log( string $msg ) : void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Product Designer] ' . $msg );
        }
    }
}
