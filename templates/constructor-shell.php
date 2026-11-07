<?php
/**
 * Mount root pentru pagina Constructor (shortcode `[pd_constructor]`).
 *
 * Întreaga interfață (cei 3 pași + editorul) e randată de aplicația Vue
 * `ConstructorApp`, montată pe acest element. Config-ul de bootstrap
 * (PDConstructorData) vine prin atributul `data-pd-config`.
 *
 * Variabile disponibile:
 *   $pd_bootstrap (array)
 *
 * Override din temă: copy în theme/product-designer/constructor-shell.php
 * (acum doar elementul root contează — restul e Vue).
 *
 * @package ProductDesigner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'DONOTCACHEPAGE' ) ) {
    define( 'DONOTCACHEPAGE', true );
}
?>
<div id="pd-constructor-app" data-pd-config="<?php echo esc_attr( wp_json_encode( $pd_bootstrap ) ); ?>"></div>
