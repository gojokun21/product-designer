<?php
/**
 * PSR-4-ish autoloader for the plugin.
 *
 * Maps `ProductDesigner\Foo\Bar_Baz` to `includes/foo/class-bar-baz.php`.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Autoloader {

    public static function register() : void {
        spl_autoload_register( [ self::class, 'load' ] );
    }

    public static function load( string $class ) : void {
        if ( strpos( $class, __NAMESPACE__ . '\\' ) !== 0 ) {
            return;
        }

        $relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
        $parts    = explode( '\\', $relative );
        $short    = array_pop( $parts );

        $is_interface = strpos( $short, 'Interface_' ) === 0 || substr( $short, -10 ) === '_Interface';
        $prefix       = $is_interface ? 'interface-' : 'class-';

        $filename = $prefix . strtolower( str_replace( '_', '-', $short ) ) . '.php';
        $subpath  = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
        $path     = PD_PLUGIN_DIR . 'includes/' . $subpath . $filename;

        if ( is_readable( $path ) ) {
            require_once $path;
        }
    }
}
