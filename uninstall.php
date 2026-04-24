<?php
/**
 * Uninstall routine.
 *
 * @package ProductDesigner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove product meta.
$meta_keys = [
    '_pd_enabled',
    '_pd_mockup_image_id',
    // Note: legacy print_area meta keys (_pd_print_area_x/y/width/height) also cleaned up
    // în caz că plugin-ul a fost instalat într-o versiune anterioară.
    '_pd_print_area_x',
    '_pd_print_area_y',
    '_pd_print_area_width',
    '_pd_print_area_height',
];
foreach ( $meta_keys as $key ) {
    $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $key ] );
}

// Remove plugin options.
delete_option( 'pd_settings' );
delete_option( 'pd_db_version' );

// NOTE: design files stored in uploads/product-designer/ are intentionally NOT removed
// to preserve order history. Administrators can delete the folder manually.
