<?php
/**
 * Auto-creare pagină WP „Constructor" la activarea plugin-ului.
 *
 * Salvează ID-ul în option `pd_constructor_page_id` și expune o metodă
 * de re-creare pentru cazul în care adminul șterge accidental pagina.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Page_Installer {

    public const OPTION_PAGE_ID = 'pd_constructor_page_id';

    /**
     * Creează pagina dacă nu există. Idempotent.
     *
     * @return int Page ID (0 dacă a eșuat).
     */
    public static function install() : int {
        $existing_id = (int) get_option( self::OPTION_PAGE_ID );

        if ( $existing_id && self::page_is_valid( $existing_id ) ) {
            return $existing_id;
        }

        // Caută o pagină existentă cu slug-ul așteptat (ca să nu duplicăm).
        $existing = get_page_by_path( PD_CONSTRUCTOR_SLUG, OBJECT, 'page' );
        if ( $existing instanceof \WP_Post && $existing->post_status !== 'trash' ) {
            update_option( self::OPTION_PAGE_ID, (int) $existing->ID );
            return (int) $existing->ID;
        }

        // Creează pagină nouă.
        $page_id = wp_insert_post( [
            'post_title'   => __( 'Constructor', 'product-designer' ),
            'post_name'    => PD_CONSTRUCTOR_SLUG,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[' . PD_CONSTRUCTOR_SHORTCODE . ']',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true );

        if ( is_wp_error( $page_id ) || ! $page_id ) {
            return 0;
        }

        update_option( self::OPTION_PAGE_ID, (int) $page_id );
        return (int) $page_id;
    }

    /**
     * Verifică dacă pagina cu ID-ul stocat încă există și e publicată.
     */
    public static function page_is_valid( int $page_id ) : bool {
        $post = get_post( $page_id );
        return $post instanceof \WP_Post && $post->post_type === 'page' && $post->post_status !== 'trash';
    }

    public static function get_page_id() : int {
        return (int) get_option( self::OPTION_PAGE_ID );
    }

    public static function get_page_url() : string {
        $id = self::get_page_id();
        return $id ? (string) get_permalink( $id ) : '';
    }
}
