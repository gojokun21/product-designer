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
        // Reserved for future scheduled cleanup / transient flush.
        wp_cache_flush();
    }
}
