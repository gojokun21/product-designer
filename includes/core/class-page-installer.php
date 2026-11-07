<?php
/**
 * Auto-creare pagină WP „Constructor" la activarea plugin-ului.
 *
 * Salvează ID-ul paginii din limba implicită în option `pd_constructor_page_id`
 * și expune o metodă de re-creare pentru cazul în care adminul șterge accidental
 * pagina.
 *
 * Cu Polylang activ pagina trebuie să existe în fiecare limbă, altfel language
 * switcher-ul de pe /constructor/ duce în homepage, iar `[pd_constructor]` nu
 * are unde rula. `ensure_translations()` creează traducerile lipsă și le leagă
 * între ele; ID-ul canonic rămâne cel din limba implicită, iar `get_page_id()`
 * îl traduce în limba cerută.
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
     * Creează pagina dacă nu există, în toate limbile. Idempotent.
     *
     * @return int Page ID în limba implicită (0 dacă a eșuat).
     */
    public static function install() : int {
        $page_id = self::install_base_page();
        if ( $page_id ) {
            self::ensure_translations( $page_id );
        }
        return $page_id;
    }

    /**
     * Pagina „canonică" (limba implicită), cea al cărei ID stă în option.
     */
    private static function install_base_page() : int {
        $existing_id = (int) get_option( self::OPTION_PAGE_ID );

        if ( $existing_id && self::page_is_valid( $existing_id ) ) {
            return $existing_id;
        }

        // Caută o pagină existentă cu slug-ul așteptat (ca să nu duplicăm).
        $existing = self::find_existing_page();
        if ( $existing instanceof \WP_Post ) {
            update_option( self::OPTION_PAGE_ID, (int) $existing->ID );
            return (int) $existing->ID;
        }

        $page_id = self::insert_page( I18n_Polylang::default_lang() );
        if ( ! $page_id ) {
            return 0;
        }

        update_option( self::OPTION_PAGE_ID, $page_id );
        return $page_id;
    }

    /**
     * Creează traducerile lipsă ale paginii Constructor și le leagă de original.
     *
     * No-op fără Polylang. Rulează pe fiecare `admin_init`, dar costă doar un
     * `pll_get_post_translations()` când totul e deja pe loc.
     */
    public static function ensure_translations( int $base_page_id ) : void {
        if ( ! $base_page_id || ! I18n_Polylang::is_active() || ! function_exists( 'pll_save_post_translations' ) ) {
            return;
        }

        $languages = (array) pll_languages_list( [ 'fields' => 'slug' ] );
        if ( count( $languages ) < 2 ) {
            return;
        }

        $translations = (array) pll_get_post_translations( $base_page_id );
        $created      = false;

        foreach ( $languages as $lang ) {
            $existing = isset( $translations[ $lang ] ) ? (int) $translations[ $lang ] : 0;
            if ( $existing && self::page_is_valid( $existing ) ) {
                continue;
            }

            $new_id = self::insert_page( (string) $lang );
            if ( $new_id ) {
                $translations[ $lang ] = $new_id;
                $created               = true;
            }
        }

        if ( $created ) {
            pll_save_post_translations( array_map( 'intval', $translations ) );
        }
    }

    /**
     * Inserează pagina și îi setează limba. Slug-ul e re-aplicat după setarea
     * limbii: modulul „share slugs" din Polylang Pro permite același slug în
     * limbi diferite, dar doar dacă limba e cunoscută în momentul verificării
     * de unicitate — la insert încă nu e, deci WP ar adăuga un sufix.
     *
     * @return int 0 la eșec.
     */
    private static function insert_page( string $lang ) : int {
        $page_id = wp_insert_post( [
            'post_title'     => __( 'Constructor', 'product-designer' ),
            'post_name'      => PD_CONSTRUCTOR_SLUG,
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_content'   => '[' . PD_CONSTRUCTOR_SHORTCODE . ']',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true );

        if ( is_wp_error( $page_id ) || ! $page_id ) {
            return 0;
        }
        $page_id = (int) $page_id;

        if ( $lang !== '' && I18n_Polylang::is_active() && function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $page_id, $lang );

            $post = get_post( $page_id );
            if ( $post instanceof \WP_Post && $post->post_name !== PD_CONSTRUCTOR_SLUG ) {
                wp_update_post( [ 'ID' => $page_id, 'post_name' => PD_CONSTRUCTOR_SLUG ] );
            }
        }

        return $page_id;
    }

    /**
     * Pagina existentă cu slug-ul nostru. Cu Polylang preferăm varianta din
     * limba implicită — `get_page_by_path()` nu știe de limbi și ar putea
     * întoarce traducerea.
     */
    private static function find_existing_page() : ?\WP_Post {
        $pages = get_posts( [
            'post_type'        => 'page',
            'name'             => PD_CONSTRUCTOR_SLUG,
            'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page'   => 10,
            'suppress_filters' => true,
            'lang'             => '', // Toate limbile (no-op fără Polylang).
        ] );

        if ( empty( $pages ) ) {
            return null;
        }

        $default_lang = I18n_Polylang::default_lang();
        if ( $default_lang !== '' ) {
            foreach ( $pages as $page ) {
                if ( I18n_Polylang::post_lang( (int) $page->ID ) === $default_lang ) {
                    return $page;
                }
            }
        }

        return $pages[0];
    }

    /**
     * Verifică dacă pagina cu ID-ul stocat încă există și e publicată.
     */
    public static function page_is_valid( int $page_id ) : bool {
        $post = get_post( $page_id );
        return $post instanceof \WP_Post && $post->post_type === 'page' && $post->post_status !== 'trash';
    }

    /**
     * ID-ul paginii Constructor în limba cerută (implicit: limba curentă).
     * Cade pe pagina canonică dacă traducerea lipsește.
     */
    public static function get_page_id( string $lang = '' ) : int {
        $base = (int) get_option( self::OPTION_PAGE_ID );
        if ( ! $base || ! I18n_Polylang::is_active() ) {
            return $base;
        }

        $lang       = $lang !== '' ? $lang : I18n_Polylang::current_lang();
        $translated = I18n_Polylang::translate_post( $base, $lang );

        return $translated && self::page_is_valid( $translated ) ? $translated : $base;
    }

    public static function get_page_url( string $lang = '' ) : string {
        $id = self::get_page_id( $lang );
        return $id ? (string) get_permalink( $id ) : '';
    }
}
