<?php
/**
 * Punte Polylang.
 *
 * Plugin-ul stochează toată configurația designerului în post meta protejate
 * (`_pd_*`) și leagă template-urile de taxonomia proprie `pd_template_cat`.
 * Polylang nu duce niciuna dintre ele mai departe la crearea unei traduceri:
 *
 *  - `PLL_Sync_Post_Metas::get_metas_to_copy()` elimină orice cheie care trece
 *    prin `is_protected_meta()`, adică tot ce începe cu `_`;
 *  - lista implicită `pll_copy_taxonomies` (plus ce adaugă Polylang for
 *    WooCommerce: product_type / product_cat / product_tag / product_visibility)
 *    nu conține taxonomii custom non-publice.
 *
 * Clasa asta închide golul: declară meta-urile și taxonomia noastră la copiere,
 * traduce ID-urile de atașament când media e tradusă, expune helperi de limbă
 * folosiți de REST / cart / storage și înregistrează numele categoriilor de
 * constructor ca stringuri traductibile (taxonomia rămâne netradusă — termenii
 * sunt partajați între limbi, doar eticheta diferă).
 *
 * Tot ce e aici e no-op dacă Polylang nu e activ.
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class I18n_Polylang {

    /** Grupul sub care apar stringurile noastre în Languages -> String translations. */
    public const STRING_GROUP = 'Product Designer';

    /**
     * Meta-urile care trebuie să însoțească un produs în traducerile lui.
     * Fără ele traducerea e un produs gol: fără buton „Personalizează", fără
     * mockup, fără zonă imprimabilă, invizibil în Constructor.
     *
     * @return string[]
     */
    public static function product_metas() : array {
        return [
            Design_Storage::META_ENABLED,
            Design_Storage::META_MOCKUP,
            Design_Storage::META_MOCKUP_BACK,
            Design_Storage::META_MOCKUP_COLOR_ATTR,
            Design_Storage::META_MOCKUP_BY_COLOR,
            Design_Storage::META_GARMENT_TYPE,
            Design_Storage::META_PRINT_AREA,
            Design_Storage::META_PRINT_SIZES,
            Templates::META_IS_TEMPLATE,
        ];
    }

    /** Meta-uri care conțin ID-uri de atașament și trebuie traduse dacă media e tradusă. */
    private static function attachment_metas() : array {
        return [ Design_Storage::META_MOCKUP, Design_Storage::META_MOCKUP_BACK ];
    }

    public static function is_active() : bool {
        return function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post' );
    }

    /** True cat timp am comutat noi locale-ul pentru un request REST. */
    private bool $switched_locale = false;

    public function register() : void {
        if ( ! self::is_active() ) {
            return;
        }

        add_filter( 'pll_copy_post_metas',     [ $this, 'copy_post_metas' ], 10, 1 );
        add_filter( 'pll_translate_post_meta', [ $this, 'translate_post_meta' ], 10, 3 );
        add_filter( 'pll_copy_taxonomies',     [ $this, 'copy_taxonomies' ], 10, 1 );

        // Prioritate 20: dupa PLL_REST_Request::set_language() (10), ca limba
        // ceruta sa fie deja stabilita.
        add_filter( 'rest_pre_dispatch',  [ $this, 'switch_rest_locale' ], 20, 3 );
        add_filter( 'rest_post_dispatch', [ $this, 'restore_rest_locale' ], 20, 3 );

        // Stringurile se înregistrează doar în admin — `pll_register_string()`
        // e no-op în afara lui (include/api.php testează PLL_Admin_Base).
        if ( is_admin() ) {
            add_action( 'admin_init', [ $this, 'register_category_strings' ] );
        }
    }

    /* ---------------------------------------------------------------------
     * Filtre Polylang
     * ------------------------------------------------------------------ */

    /**
     * @param string[] $keys
     * @return string[]
     */
    public function copy_post_metas( $keys ) : array {
        return array_merge( (array) $keys, self::product_metas() );
    }

    /**
     * Media e traductibilă pe acest site (`media_support`), deci un mockup poate
     * avea propriul atașament per limbă. Dacă există, îl folosim; altfel păstrăm
     * atașamentul sursă (aceeași imagine în toate limbile).
     *
     * @param mixed  $value Valoarea meta din postul sursă.
     * @param string $key   Cheia meta.
     * @param string $lang  Slug-ul limbii țintă.
     * @return mixed
     */
    public function translate_post_meta( $value, $key, $lang ) {
        if ( ! is_string( $lang ) || $lang === '' ) {
            return $value;
        }

        if ( in_array( $key, self::attachment_metas(), true ) ) {
            $translated = self::translate_attachment_id( (int) $value, $lang );
            return $translated ?: $value;
        }

        if ( $key === Design_Storage::META_MOCKUP_BY_COLOR && is_array( $value ) ) {
            foreach ( $value as $slug => $sides ) {
                if ( ! is_array( $sides ) ) {
                    continue;
                }
                foreach ( [ 'front', 'back' ] as $side ) {
                    $translated = self::translate_attachment_id( (int) ( $sides[ $side ] ?? 0 ), $lang );
                    if ( $translated ) {
                        $value[ $slug ][ $side ] = $translated;
                    }
                }
            }
        }

        return $value;
    }

    /**
     * @param string[] $taxonomies
     * @return string[]
     */
    public function copy_taxonomies( $taxonomies ) : array {
        $taxonomies   = (array) $taxonomies;
        $taxonomies[] = Templates::TAXONOMY;
        return $taxonomies;
    }

    /**
     * Comută locale-ul pe durata unui request REST din namespace-ul nostru.
     *
     * Polylang filtrează `locale` doar pe frontend (`PLL_Frontend_Filters`).
     * Într-un request REST limba se stabilește abia la `rest_pre_dispatch`,
     * după ce textdomain-urile s-au încărcat deja în limba implicită — deci
     * `__()` ar întoarce româna chiar și pentru `?lang=ru`, iar blocul `i18n`
     * trimis către editorul Vue ar ajunge în limba greșită.
     *
     * Comutarea e limitată la rutele `product-designer/v1`, ca să nu schimbăm
     * limba altor endpointuri din același request.
     *
     * @param mixed            $result
     * @param \WP_REST_Server  $server
     * @param \WP_REST_Request $request
     * @return mixed Nemodificat.
     */
    public function switch_rest_locale( $result, $server, $request ) {
        if ( ! $request instanceof \WP_REST_Request ) {
            return $result;
        }
        if ( strpos( ltrim( (string) $request->get_route(), '/' ), PD_REST_NS ) !== 0 ) {
            return $result;
        }

        $lang = self::sanitize_lang( (string) $request->get_param( 'lang' ) );
        if ( $lang === '' ) {
            return $result;
        }

        $locale = self::locale_for( $lang );
        if ( $locale === '' || $locale === get_locale() ) {
            return $result;
        }

        $this->switched_locale = switch_to_locale( $locale );

        return $result;
    }

    /**
     * @param mixed $response
     * @return mixed Nemodificat.
     */
    public function restore_rest_locale( $response, $server, $request ) {
        if ( $this->switched_locale ) {
            restore_previous_locale();
            $this->switched_locale = false;
        }
        return $response;
    }

    /** Locale-ul WP al unei limbi Polylang (ex. 'ru' -> 'ru_RU'). */
    public static function locale_for( string $lang ) : string {
        if ( $lang === '' || ! self::is_active() || ! function_exists( 'pll_languages_list' ) ) {
            return '';
        }
        $slugs   = (array) pll_languages_list( [ 'fields' => 'slug' ] );
        $locales = (array) pll_languages_list( [ 'fields' => 'locale' ] );
        if ( count( $slugs ) !== count( $locales ) ) {
            return '';
        }
        $map = array_combine( $slugs, $locales );
        return isset( $map[ $lang ] ) ? (string) $map[ $lang ] : '';
    }

    /**
     * Categoriile de constructor rămân netraduse (aceiași termeni în toate
     * limbile, ca tax_query-ul să funcționeze identic), dar numele lor devine
     * traductibil din Languages -> String translations.
     */
    public function register_category_strings() : void {
        if ( ! function_exists( 'pll_register_string' ) ) {
            return;
        }
        $terms = get_terms( [ 'taxonomy' => Templates::TAXONOMY, 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) ) {
            return;
        }
        foreach ( $terms as $term ) {
            pll_register_string( 'pd_cat_' . $term->slug, $term->name, self::STRING_GROUP );
        }
    }

    /* ---------------------------------------------------------------------
     * Helperi de limbă
     * ------------------------------------------------------------------ */

    /** Limba curentă (slug), cu fallback pe limba implicită. '' fără Polylang. */
    public static function current_lang() : string {
        if ( ! self::is_active() ) {
            return '';
        }
        $lang = pll_current_language( 'slug' );
        if ( is_string( $lang ) && $lang !== '' ) {
            return $lang;
        }
        return self::default_lang();
    }

    public static function default_lang() : string {
        if ( ! self::is_active() || ! function_exists( 'pll_default_language' ) ) {
            return '';
        }
        $lang = pll_default_language( 'slug' );
        return is_string( $lang ) ? $lang : '';
    }

    /** Întoarce $lang doar dacă e un slug de limbă existent, altfel ''. */
    public static function sanitize_lang( string $lang ) : string {
        if ( $lang === '' || ! self::is_active() || ! function_exists( 'pll_languages_list' ) ) {
            return '';
        }
        $all = (array) pll_languages_list( [ 'fields' => 'slug' ] );
        return in_array( $lang, $all, true ) ? $lang : '';
    }

    /**
     * ID-ul postului + toate traducerile lui, cu el pe prima poziție.
     *
     * @return int[]
     */
    public static function translation_ids( int $post_id ) : array {
        if ( ! $post_id ) {
            return [];
        }
        if ( ! self::is_active() || ! function_exists( 'pll_get_post_translations' ) ) {
            return [ $post_id ];
        }
        $ids = array_map( 'absint', array_values( (array) pll_get_post_translations( $post_id ) ) );
        array_unshift( $ids, $post_id );
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * Prima traducere (inclusiv el însuși) care are efectiv cheia $meta_key.
     *
     * Acoperă traducerile create înainte de acest bridge sau importate cu un
     * tool care nu trece prin Polylang: acolo meta-urile `_pd_*` lipsesc cu
     * totul, iar produsul tradus ar apărea ca „designer dezactivat".
     * Un `_pd_enabled = 'no'` explicit e respectat — meta există, deci sursa e
     * chiar produsul cerut.
     */
    public static function meta_source_id( int $post_id, string $meta_key ) : int {
        if ( ! $post_id || metadata_exists( 'post', $post_id, $meta_key ) ) {
            return $post_id;
        }
        foreach ( self::translation_ids( $post_id ) as $id ) {
            if ( $id !== $post_id && metadata_exists( 'post', $id, $meta_key ) ) {
                return $id;
            }
        }
        return $post_id;
    }

    /**
     * Slug-ul din $candidates care e o traducere a termenului $slug.
     *
     * Polylang for WooCommerce face traductibile toate taxonomiile `pa_*`, deci
     * variația RU a unui produs are `attribute_pa_culoare = <slug ru>`, în timp
     * ce harta `_pd_mockup_by_color` e cheiată pe slug-urile limbii în care a
     * fost salvată — și se sincronizează așa, neschimbată, pe toate
     * traducerile. Limba cheilor nu e deci neapărat limba produsului: căutăm
     * printre toate traducerile termenului.
     *
     * Întoarce slug-ul original dacă taxonomia nu e tradusă sau nicio
     * traducere nu e printre candidați.
     *
     * @param string[] $candidates
     */
    public static function match_term_slug( string $slug, string $taxonomy, array $candidates ) : string {
        if ( $slug === '' || ! self::is_active() ) {
            return $slug;
        }
        if ( ! taxonomy_exists( $taxonomy ) || ! function_exists( 'pll_is_translated_taxonomy' ) ) {
            return $slug;
        }
        if ( ! pll_is_translated_taxonomy( $taxonomy ) ) {
            return $slug;
        }
        $term = get_term_by( 'slug', $slug, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            return $slug;
        }
        foreach ( pll_get_term_translations( $term->term_id ) as $term_id ) {
            $translated = get_term( (int) $term_id, $taxonomy );
            if ( $translated instanceof \WP_Term && in_array( $translated->slug, $candidates, true ) ) {
                return $translated->slug;
            }
        }
        return $slug;
    }

    /**
     * Eticheta unui termen în limba curentă: prin traducerea termenului dacă
     * taxonomia e tradusă (atribute WooCommerce), prin string translation dacă
     * nu (categoriile noastre de constructor).
     */
    public static function term_label( \WP_Term $term ) : string {
        if ( ! self::is_active() ) {
            return $term->name;
        }
        if ( function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $term->taxonomy ) ) {
            $translated = get_term( (int) pll_get_term( $term->term_id, self::current_lang() ), $term->taxonomy );
            return $translated instanceof \WP_Term ? $translated->name : $term->name;
        }
        return function_exists( 'pll__' ) ? (string) pll__( $term->name ) : $term->name;
    }

    /** Limba unui post (slug), sau '' dacă nu are / Polylang lipsește. */
    public static function post_lang( int $post_id ) : string {
        if ( ! $post_id || ! self::is_active() || ! function_exists( 'pll_get_post_language' ) ) {
            return '';
        }
        $lang = pll_get_post_language( $post_id, 'slug' );
        return is_string( $lang ) ? $lang : '';
    }

    /** ID-ul postului tradus în $lang, sau 0 dacă nu există traducere. */
    public static function translate_post( int $post_id, string $lang ) : int {
        if ( ! $post_id || $lang === '' || ! self::is_active() ) {
            return 0;
        }
        return (int) pll_get_post( $post_id, $lang );
    }

    private static function translate_attachment_id( int $attachment_id, string $lang ) : int {
        if ( ! $attachment_id ) {
            return 0;
        }
        $translated = (int) pll_get_post( $attachment_id, $lang );
        return $translated !== $attachment_id ? $translated : 0;
    }
}
