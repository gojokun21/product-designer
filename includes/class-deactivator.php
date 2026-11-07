<?php
/**
 * Deactivation logic.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Deactivator {

    public static function deactivate() : void {
        wp_unschedule_hook( \ProductDesigner\Core\Cleanup::CRON_HOOK );
        wp_cache_flush();
    }
}
