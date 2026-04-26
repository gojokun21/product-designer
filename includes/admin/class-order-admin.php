<?php
/**
 * Renders design preview + element breakdown + download links
 * inside the order edit screen.
 *
 * Works with both legacy post-based orders and HPOS (custom order tables).
 *
 * @package ProductDesigner
 */

namespace ProductDesigner\Admin;

use ProductDesigner\Core\Design_Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order_Admin {

    private Design_Storage $storage;

    public function __construct( Design_Storage $storage ) {
        $this->storage = $storage;
    }

    public function register() : void {
        add_action( 'woocommerce_after_order_itemmeta', [ $this, 'render_itemmeta' ], 10, 3 );
        add_action( 'admin_post_pd_download_design',    [ $this, 'handle_download' ] );
    }

    public function render_itemmeta( int $item_id, $item, $product ) : void {
        if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
            return;
        }

        $product_id         = (int) $item->get_product_id();
        $designer_enabled   = $product_id && $this->storage->is_enabled_for_product( $product_id );
        $design_id          = (string) $item->get_meta( Design_Storage::ITEM_META_DESIGN_ID, true );
        $preview_front      = (string) $item->get_meta( Design_Storage::ITEM_META_PREVIEW_URL, true );
        $preview_back       = (string) $item->get_meta( Design_Storage::ITEM_META_PREVIEW_BACK_URL, true );

        // DIAGNOSTIC: produsul are designer activat, dar nu există design_id
        // pe acest item. Înseamnă că fluxul cart→order s-a rupt.
        if ( $designer_enabled && ! $design_id ) {
            $this->render_missing_design_notice( $item );
            return;
        }

        if ( ! $design_id ) {
            return;
        }

        $download_json = wp_nonce_url(
            admin_url( 'admin-post.php?action=pd_download_design&type=json&design=' . rawurlencode( $design_id ) ),
            'pd_download_' . $design_id
        );

        $design = $this->storage->get_design_json( $design_id );
        // After get_design_json() the structure is normalized to v2 shape.
        // Legacy v1 designs come back with version=1, front=<full canvas>, back=null.
        $front_canvas = is_array( $design ) && isset( $design['front'] ) && is_array( $design['front'] ) ? $design['front'] : null;
        $back_canvas  = is_array( $design ) && isset( $design['back'] )  && is_array( $design['back'] )  ? $design['back']  : null;

        $any_data = $front_canvas || $back_canvas || $preview_front || $preview_back;

        ?>
        <div class="pd-order-design">
            <div class="pd-order-design__header">
                <strong><?php esc_html_e( 'Design personalizat', 'product-designer' ); ?></strong>
                <code class="pd-order-design__id"><?php echo esc_html( $design_id ); ?></code>
            </div>

            <p class="pd-order-links">
                <?php if ( $preview_front ) : ?>
                    <a class="button button-primary" href="<?php echo esc_url( $this->build_download_url( $design_id, 'png', 'front' ) ); ?>">
                        <?php esc_html_e( 'Descarcă PNG Față', 'product-designer' ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( $preview_back ) : ?>
                    <a class="button button-primary" href="<?php echo esc_url( $this->build_download_url( $design_id, 'png', 'back' ) ); ?>">
                        <?php esc_html_e( 'Descarcă PNG Spate', 'product-designer' ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( ! $preview_front && ! $preview_back ) : ?>
                    <a class="button button-primary" href="<?php echo esc_url( $this->build_download_url( $design_id, 'png', null ) ); ?>">
                        <?php esc_html_e( 'Descarcă preview PNG', 'product-designer' ); ?>
                    </a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url( $download_json ); ?>">
                    <?php esc_html_e( 'Descarcă JSON brut', 'product-designer' ); ?>
                </a>
            </p>

            <?php
            $sides_to_render = [];
            if ( $front_canvas || $preview_front ) {
                $sides_to_render[] = [ 'label' => __( 'Față', 'product-designer' ), 'preview' => $preview_front, 'canvas' => $front_canvas, 'side' => 'front' ];
            }
            if ( $back_canvas || $preview_back ) {
                $sides_to_render[] = [ 'label' => __( 'Spate', 'product-designer' ), 'preview' => $preview_back, 'canvas' => $back_canvas, 'side' => 'back' ];
            }

            foreach ( $sides_to_render as $entry ) {
                $this->render_side_section( $entry['label'], $entry['preview'], $entry['canvas'], $entry['side'] );
            }
            ?>

            <?php if ( ! $any_data ) : ?>
                <p class="pd-order-design__empty">
                    <?php esc_html_e( 'JSON-ul design-ului nu a putut fi citit de pe disk. Este posibil să fi fost șters.', 'product-designer' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render one side's section: preview image + element breakdown cards.
     */
    private function render_side_section( string $label, string $preview_url, ?array $canvas, string $side ) : void {
        $objects = is_array( $canvas ) && isset( $canvas['objects'] ) && is_array( $canvas['objects'] )
            ? $canvas['objects']
            : [];

        $text_objects  = [];
        $image_objects = [];
        foreach ( $objects as $obj ) {
            $type = isset( $obj['type'] ) ? (string) $obj['type'] : '';
            if ( in_array( $type, [ 'i-text', 'text', 'textbox' ], true ) ) {
                $text_objects[] = $obj;
            } elseif ( $type === 'image' ) {
                $image_objects[] = $obj;
            }
        }
        ?>
        <div class="pd-order-side pd-order-side--<?php echo esc_attr( $side ); ?>">
            <h4 class="pd-order-side__label"><?php echo esc_html( $label ); ?></h4>

            <?php if ( $preview_url ) : ?>
                <div class="pd-order-section pd-order-section--preview">
                    <img src="<?php echo esc_url( $preview_url ); ?>" alt="" />
                </div>
            <?php endif; ?>

            <?php if ( $text_objects ) : ?>
                <div class="pd-order-section pd-order-section--texts">
                    <span class="pd-order-section__label">
                        <?php
                        printf(
                            /* translators: %d: number of text elements */
                            esc_html( _n( '%d text adăugat de client', '%d texte adăugate de client', count( $text_objects ), 'product-designer' ) ),
                            count( $text_objects )
                        );
                        ?>
                    </span>
                    <div class="pd-elements">
                        <?php foreach ( $text_objects as $i => $obj ) : ?>
                            <?php $this->render_text_card( $obj, $i + 1 ); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $image_objects ) : ?>
                <div class="pd-order-section pd-order-section--images">
                    <span class="pd-order-section__label">
                        <?php
                        printf(
                            /* translators: %d: number of image elements */
                            esc_html( _n( '%d imagine încărcată de client', '%d imagini încărcate de client', count( $image_objects ), 'product-designer' ) ),
                            count( $image_objects )
                        );
                        ?>
                    </span>
                    <div class="pd-elements">
                        <?php foreach ( $image_objects as $i => $obj ) : ?>
                            <?php $this->render_image_card( $obj, $i + 1 ); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function build_download_url( string $design_id, string $type, ?string $side ) : string {
        $args = [ 'action' => 'pd_download_design', 'type' => $type, 'design' => $design_id ];
        if ( $side ) {
            $args['side'] = $side;
        }
        return wp_nonce_url(
            add_query_arg( $args, admin_url( 'admin-post.php' ) ),
            'pd_download_' . $design_id
        );
    }

    private function render_text_card( array $obj, int $nr ) : void {
        $text      = (string) ( $obj['text'] ?? '' );
        $font      = (string) ( $obj['fontFamily'] ?? 'Arial' );
        $size      = (float) ( $obj['fontSize'] ?? 0 );
        $scale_x   = (float) ( $obj['scaleX'] ?? 1 );
        $scale_y   = (float) ( $obj['scaleY'] ?? 1 );
        $bold      = ( $obj['fontWeight'] ?? '' ) === 'bold' || (int) ( $obj['fontWeight'] ?? 0 ) >= 600;
        $italic    = ( $obj['fontStyle'] ?? '' ) === 'italic';
        $underline = ! empty( $obj['underline'] );
        $color     = $this->normalize_color( $obj['fill'] ?? '#111111' );
        $align     = (string) ( $obj['textAlign'] ?? 'left' );
        $left      = (int) round( (float) ( $obj['left'] ?? 0 ) );
        $top       = (int) round( (float) ( $obj['top']  ?? 0 ) );
        $angle     = (float) ( $obj['angle'] ?? 0 );
        $effective_size = (int) round( $size * $scale_y );

        $style_tags = [];
        if ( $bold )      { $style_tags[] = 'Bold'; }
        if ( $italic )    { $style_tags[] = 'Italic'; }
        if ( $underline ) { $style_tags[] = 'Underline'; }

        // Construim stilul inline pentru redarea sample-ului, cu culoare
        // validată și fonturi în whitelist-ul frontend-ului.
        $preview_style = sprintf(
            'font-family:%s; color:%s; font-weight:%s; font-style:%s; %stext-align:%s;',
            esc_attr( $font ),
            esc_attr( $color ),
            $bold ? 'bold' : 'normal',
            $italic ? 'italic' : 'normal',
            $underline ? 'text-decoration:underline;' : '',
            esc_attr( in_array( $align, [ 'left', 'center', 'right' ], true ) ? $align : 'left' )
        );
        ?>
        <div class="pd-element pd-element--text">
            <div class="pd-element__head">
                <span class="pd-element__nr">#<?php echo (int) $nr; ?></span>
                <span class="pd-element__kind"><?php esc_html_e( 'Text', 'product-designer' ); ?></span>
            </div>
            <div class="pd-element__sample" style="<?php echo esc_attr( $preview_style ); ?>">
                <?php echo esc_html( $text !== '' ? $text : '(gol)' ); ?>
            </div>
            <dl class="pd-element__props">
                <dt><?php esc_html_e( 'Font', 'product-designer' ); ?></dt>
                <dd>
                    <?php echo esc_html( $font ); ?>
                    <?php if ( $style_tags ) : ?>
                        · <?php echo esc_html( implode( ' · ', $style_tags ) ); ?>
                    <?php endif; ?>
                </dd>

                <dt><?php esc_html_e( 'Mărime', 'product-designer' ); ?></dt>
                <dd>
                    <?php echo (int) $size; ?>px
                    <?php if ( $scale_y !== 1.0 ) : ?>
                        <span class="pd-muted">(randat la <?php echo (int) $effective_size; ?>px, scaleY=<?php echo esc_html( (string) round( $scale_y, 3 ) ); ?>)</span>
                    <?php endif; ?>
                </dd>

                <dt><?php esc_html_e( 'Culoare', 'product-designer' ); ?></dt>
                <dd>
                    <span class="pd-color-swatch" style="background:<?php echo esc_attr( $color ); ?>;"></span>
                    <code><?php echo esc_html( $color ); ?></code>
                </dd>

                <dt><?php esc_html_e( 'Aliniere', 'product-designer' ); ?></dt>
                <dd><?php echo esc_html( $align ); ?></dd>

                <dt><?php esc_html_e( 'Poziție (px)', 'product-designer' ); ?></dt>
                <dd>X: <?php echo (int) $left; ?>, Y: <?php echo (int) $top; ?><?php if ( $angle ) : ?> · rotire: <?php echo esc_html( (string) round( $angle, 1 ) ); ?>°<?php endif; ?></dd>
            </dl>
        </div>
        <?php
    }

    private function render_image_card( array $obj, int $nr ) : void {
        $src     = isset( $obj['src'] ) ? (string) $obj['src'] : '';
        $left    = (int) round( (float) ( $obj['left'] ?? 0 ) );
        $top     = (int) round( (float) ( $obj['top']  ?? 0 ) );
        $w       = (int) ( $obj['width']  ?? 0 );
        $h       = (int) ( $obj['height'] ?? 0 );
        $scale_x = (float) ( $obj['scaleX'] ?? 1 );
        $scale_y = (float) ( $obj['scaleY'] ?? 1 );
        $final_w = (int) round( $w * $scale_x );
        $final_h = (int) round( $h * $scale_y );
        $angle   = (float) ( $obj['angle'] ?? 0 );

        // Nu afișăm src-uri externe ca <img> (scurgere de informații / tracking).
        $safe_src = $this->is_local_url( $src ) ? $src : '';
        $filename = $src ? basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) : '';
        ?>
        <div class="pd-element pd-element--image">
            <div class="pd-element__head">
                <span class="pd-element__nr">#<?php echo (int) $nr; ?></span>
                <span class="pd-element__kind"><?php esc_html_e( 'Imagine', 'product-designer' ); ?></span>
            </div>
            <?php if ( $safe_src ) : ?>
                <a href="<?php echo esc_url( $safe_src ); ?>" target="_blank" rel="noopener" class="pd-element__thumb">
                    <img src="<?php echo esc_url( $safe_src ); ?>" alt="" loading="lazy" />
                </a>
            <?php endif; ?>
            <dl class="pd-element__props">
                <dt><?php esc_html_e( 'Fișier', 'product-designer' ); ?></dt>
                <dd>
                    <?php if ( $safe_src ) : ?>
                        <a href="<?php echo esc_url( $safe_src ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $filename ); ?></a>
                    <?php else : ?>
                        <span class="pd-muted"><?php esc_html_e( 'URL extern / inaccesibil', 'product-designer' ); ?></span>
                        <?php if ( $src ) : ?>
                            <code><?php echo esc_html( $src ); ?></code>
                        <?php endif; ?>
                    <?php endif; ?>
                </dd>

                <dt><?php esc_html_e( 'Dimensiune originală', 'product-designer' ); ?></dt>
                <dd><?php echo (int) $w; ?> × <?php echo (int) $h; ?> px</dd>

                <dt><?php esc_html_e( 'Dimensiune pe mockup', 'product-designer' ); ?></dt>
                <dd>
                    <?php echo (int) $final_w; ?> × <?php echo (int) $final_h; ?> px
                    <span class="pd-muted">(scale <?php echo esc_html( (string) round( $scale_x, 3 ) ); ?>× / <?php echo esc_html( (string) round( $scale_y, 3 ) ); ?>×)</span>
                </dd>

                <dt><?php esc_html_e( 'Poziție (px)', 'product-designer' ); ?></dt>
                <dd>X: <?php echo (int) $left; ?>, Y: <?php echo (int) $top; ?><?php if ( $angle ) : ?> · rotire: <?php echo esc_html( (string) round( $angle, 1 ) ); ?>°<?php endif; ?></dd>
            </dl>
            <?php if ( $safe_src ) : ?>
                <p class="pd-element__actions">
                    <a href="<?php echo esc_url( $safe_src ); ?>" download class="button button-small">
                        <?php esc_html_e( 'Descarcă originalul', 'product-designer' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Afișează un notice de diagnostic când produsul are designer activat
     * dar nu există meta de design pe order item — ca să vedem clar unde
     * s-a rupt fluxul (cart, checkout, Store API etc).
     */
    private function render_missing_design_notice( \WC_Order_Item_Product $item ) : void {
        global $wpdb;

        // Scanăm TOATE meta-urile itemului care încep cu `_pd_` sau `pd_`,
        // să vedem dacă măcar cheile există sub altă formă.
        $pd_meta = [];
        foreach ( $item->get_meta_data() as $meta ) {
            $key = isset( $meta->key ) ? (string) $meta->key : '';
            if ( $key !== '' && ( strpos( $key, '_pd_' ) === 0 || strpos( $key, 'pd_' ) === 0 ) ) {
                $pd_meta[ $key ] = $meta->value;
            }
        }

        // Verificăm cât de multe session-uri WC conțin cheia noastră — dacă >0
        // înseamnă că REST-ul scrie OK în session dar capture_design nu citește.
        $product_id        = (int) $item->get_product_id();
        $sessions_table    = $wpdb->prefix . 'woocommerce_sessions';
        $session_count     = 0;
        $sessions_for_prod = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) === $sessions_table ) {
            $session_count     = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$sessions_table} WHERE session_value LIKE '%pd_design_for_%'"
            );
            $sessions_for_prod = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessions_table} WHERE session_value LIKE %s",
                '%pd_design_for_' . $product_id . '%'
            ) );
        }
        ?>
        <div class="pd-order-design pd-order-design--warning">
            <div class="pd-order-design__header">
                <strong>⚠ <?php esc_html_e( 'Product Designer — design LIPSĂ', 'product-designer' ); ?></strong>
            </div>
            <p style="margin:6px 0;">
                <?php esc_html_e( 'Produsul are designer-ul activat, dar pe acest order item nu e salvat nicio meta de design. Fluxul cart → order s-a rupt.', 'product-designer' ); ?>
            </p>
            <p style="margin:6px 0;"><strong><?php esc_html_e( 'Cauze posibile:', 'product-designer' ); ?></strong></p>
            <ul style="margin:4px 0 4px 20px; list-style: disc;">
                <li><?php esc_html_e( 'Comanda a fost plasată ÎNAINTE de activarea/salvarea designer-ului (comandă veche).', 'product-designer' ); ?></li>
                <li><?php esc_html_e( 'Checkout via Store API (blocks) — hidden inputs din form.cart nu se trimit.', 'product-designer' ); ?></li>
                <li><?php esc_html_e( 'Item adăugat în coș programatic (admin manual order, REST API etc).', 'product-designer' ); ?></li>
                <li><?php esc_html_e( 'Tema folosește AJAX add-to-cart care nu serializează hidden inputs.', 'product-designer' ); ?></li>
            </ul>
            <?php if ( $pd_meta ) : ?>
                <p style="margin:6px 0;"><strong><?php esc_html_e( 'Meta-uri pd_* găsite pe item:', 'product-designer' ); ?></strong></p>
                <pre style="background:#fff;padding:8px;border:1px solid #ddd;font-size:11px;margin:4px 0;overflow:auto;max-height:200px;"><?php echo esc_html( print_r( $pd_meta, true ) ); ?></pre>
            <?php else : ?>
                <p style="margin:6px 0;"><em><?php esc_html_e( 'Niciun meta pd_* găsit pe item — cart→order n-a transferat nimic.', 'product-designer' ); ?></em></p>
            <?php endif; ?>

            <p style="margin:10px 0 4px;"><strong><?php esc_html_e( 'Diagnostic WC Session:', 'product-designer' ); ?></strong></p>
            <ul style="margin:2px 0 4px 20px; font-size: 12px;">
                <li><?php
                    /* translators: %d: number of sessions */
                    printf( esc_html__( 'Sessions WC cu cheia pd_design_for_* (oricare produs): %d', 'product-designer' ), (int) $session_count );
                ?></li>
                <li><?php
                    /* translators: 1: product id, 2: count */
                    printf( esc_html__( 'Sessions cu design salvat pentru produsul #%1$d: %2$d', 'product-designer' ), (int) $product_id, (int) $sessions_for_prod );
                ?></li>
            </ul>
            <p style="margin:4px 0; font-size: 12px; color: #555;">
                <?php if ( $session_count === 0 ) : ?>
                    <strong><?php esc_html_e( 'Concluzie:', 'product-designer' ); ?></strong>
                    <?php esc_html_e( 'REST-ul de save nu scrie în WC Session (sau clientul nu a apăsat „Salvează designul" înainte de add-to-cart). Verifică în Console la salvare că apare „[PD] WC Session OK: DA".', 'product-designer' ); ?>
                <?php else : ?>
                    <strong><?php esc_html_e( 'Concluzie:', 'product-designer' ); ?></strong>
                    <?php esc_html_e( 'REST-ul SCRIE în session, dar transfer_to_order nu găsește match în session-ul acestei comenzi. Cookie-ul session s-a pierdut între save și checkout (browser blochează, session expired, sau customer schimbat).', 'product-designer' ); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private function is_local_url( string $url ) : bool {
        if ( $url === '' ) { return false; }
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $site = wp_parse_url( home_url(), PHP_URL_HOST );
        return $host && $site && strcasecmp( $host, $site ) === 0;
    }

    private function normalize_color( $value ) : string {
        if ( ! is_string( $value ) || $value === '' ) { return '#111111'; }
        // Short hex #abc → #aabbcc.
        if ( preg_match( '/^#([0-9a-f]{3})$/i', $value, $m ) ) {
            $chars = str_split( $m[1] );
            return '#' . $chars[0] . $chars[0] . $chars[1] . $chars[1] . $chars[2] . $chars[2];
        }
        if ( preg_match( '/^#[0-9a-f]{6}$/i', $value ) ) {
            return strtolower( $value );
        }
        if ( preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $value, $m ) ) {
            return sprintf( '#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3] );
        }
        return '#111111';
    }

    public function handle_download() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permisiune insuficientă.', 'product-designer' ), '', [ 'response' => 403 ] );
        }

        $design_id = isset( $_GET['design'] )   ? sanitize_text_field( wp_unslash( $_GET['design'] ) ) : '';
        $type      = isset( $_GET['type'] )     ? sanitize_key( wp_unslash( $_GET['type'] ) )          : '';
        $side      = isset( $_GET['side'] )     ? sanitize_key( wp_unslash( $_GET['side'] ) )          : '';
        $nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! $design_id || ! wp_verify_nonce( $nonce, 'pd_download_' . $design_id ) ) {
            wp_die( esc_html__( 'Token invalid.', 'product-designer' ), '', [ 'response' => 403 ] );
        }
        if ( ! in_array( $type, [ 'png', 'json' ], true ) ) {
            wp_die( esc_html__( 'Tip necunoscut.', 'product-designer' ), '', [ 'response' => 400 ] );
        }
        if ( $side && ! in_array( $side, [ 'front', 'back' ], true ) ) {
            wp_die( esc_html__( 'Parte necunoscută.', 'product-designer' ), '', [ 'response' => 400 ] );
        }

        // PNG: side-aware lookup (legacy fallback handled inside resolve).
        // JSON: single combined file regardless of side.
        if ( $type === 'png' ) {
            $resolved_side = $side ? $side : 'front';
            $files = $this->storage->get_design_files_for_side( $design_id, $resolved_side );
            $file  = $files['png']['path'] ?? '';
        } else {
            $files = $this->storage->get_design_files( $design_id );
            $file  = $files['json']['path'] ?? '';
        }

        if ( ! $file || ! is_readable( $file ) ) {
            wp_die( esc_html__( 'Fișier inexistent.', 'product-designer' ), '', [ 'response' => 404 ] );
        }

        $mime     = $type === 'png' ? 'image/png' : 'application/json';
        $suffix   = ( $type === 'png' && $side ) ? '-' . $side : '';
        $filename = $design_id . $suffix . '.' . $type;

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $file ) );
        readfile( $file );
        exit;
    }
}
