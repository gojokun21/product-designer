<?php
/**
 * Shell HTML pentru pagina Constructor (shortcode `[pd_constructor]`).
 *
 * Variabile disponibile:
 *   $constructor_title (string)
 *
 * Override din temă: copy în theme/product-designer/constructor-shell.php
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
<div class="pd-constructor" data-step="1" aria-busy="false">

    <header class="pd-constructor__header">
        <h1 class="pd-constructor__title"><?php echo esc_html( $constructor_title ); ?></h1>
        <ol class="pd-progress" role="list">
            <li class="pd-progress__step is-active" data-target="1"><span class="pd-progress__num">1</span><span class="pd-progress__label"><?php esc_html_e( 'Categorie', 'product-designer' ); ?></span></li>
            <li class="pd-progress__step" data-target="2"><span class="pd-progress__num">2</span><span class="pd-progress__label"><?php esc_html_e( 'Produs', 'product-designer' ); ?></span></li>
            <li class="pd-progress__step" data-target="3"><span class="pd-progress__num">3</span><span class="pd-progress__label"><?php esc_html_e( 'Personalizare', 'product-designer' ); ?></span></li>
        </ol>
        <p class="pd-constructor__sub-label" data-pd-step-label></p>
    </header>

    <!-- ========== STEP 1: alege categorie ========== -->
    <section class="pd-constructor__panel" data-panel="1" aria-label="<?php esc_attr_e( 'Alege o categorie', 'product-designer' ); ?>">
        <h2 class="pd-panel__heading"><?php esc_html_e( 'Alege o categorie', 'product-designer' ); ?></h2>
        <div class="pd-categories" data-pd-categories>
            <p class="pd-loading"><?php esc_html_e( 'Se încarcă...', 'product-designer' ); ?></p>
        </div>
    </section>

    <!-- ========== STEP 2: alege produs ========== -->
    <section class="pd-constructor__panel" data-panel="2" hidden aria-label="<?php esc_attr_e( 'Alege un produs', 'product-designer' ); ?>">
        <div class="pd-breadcrumb">
            <button type="button" class="pd-breadcrumb__back" data-pd-back-to-step="1">
                <span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Schimbă categoria', 'product-designer' ); ?>
            </button>
            <span class="pd-breadcrumb__current" data-pd-current-category></span>
        </div>
        <h2 class="pd-panel__heading"><?php esc_html_e( 'Alege un produs de bază', 'product-designer' ); ?></h2>
        <div class="pd-products" data-pd-products>
            <p class="pd-loading"><?php esc_html_e( 'Se încarcă...', 'product-designer' ); ?></p>
        </div>
    </section>

    <!-- ========== STEP 2.5: variation picker (doar pentru produse variabile) ========== -->
    <section class="pd-constructor__panel" data-panel="2.5" hidden aria-label="<?php esc_attr_e( 'Alege opțiunile produsului', 'product-designer' ); ?>">
        <div class="pd-breadcrumb">
            <button type="button" class="pd-breadcrumb__back" data-pd-back-to-step="2">
                <span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Schimbă produsul', 'product-designer' ); ?>
            </button>
            <span class="pd-breadcrumb__current" data-pd-current-product-2></span>
        </div>
        <h2 class="pd-panel__heading"><?php esc_html_e( 'Alege opțiunile', 'product-designer' ); ?></h2>
        <div class="pd-variations" data-pd-variations>
            <p class="pd-loading"><?php esc_html_e( 'Se încarcă...', 'product-designer' ); ?></p>
        </div>
        <div class="pd-variation-actions" hidden data-pd-variation-actions>
            <button type="button" class="pd-tool pd-continue-to-design" disabled>
                <?php esc_html_e( 'Continuă la personalizare', 'product-designer' ); ?> &rarr;
            </button>
        </div>
    </section>

    <!-- ========== STEP 3: designer ========== -->
    <section class="pd-constructor__panel" data-panel="3" hidden aria-label="<?php esc_attr_e( 'Personalizează produsul', 'product-designer' ); ?>">
        <!-- Slim sticky bar — apare DOAR pe mobile (≤900px) via CSS. -->
        <header class="pd-step3-bar" aria-hidden="true">
            <button type="button" class="pd-step3-bar__back" data-pd-back-to-step="2" aria-label="<?php esc_attr_e( 'Înapoi', 'product-designer' ); ?>">&larr;</button>
            <span class="pd-step3-bar__title" data-pd-current-product></span>
            <button type="button" class="pd-step3-bar__save pd-save"><?php esc_html_e( 'Salvează', 'product-designer' ); ?></button>
        </header>

        <div class="pd-breadcrumb">
            <button type="button" class="pd-breadcrumb__back" data-pd-back-to-step="2">
                <span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Schimbă produsul', 'product-designer' ); ?>
            </button>
            <span class="pd-breadcrumb__current" data-pd-current-product></span>
        </div>

        <div class="pd-designer-layout">
            <!-- Tools sidebar (clonate din class-frontend.php) -->
            <aside class="pd-tools">
                <div class="pd-side-tabs" role="tablist" hidden>
                    <button type="button" class="pd-side-tab is-active" data-side="front" role="tab" aria-selected="true"><?php esc_html_e( 'Față', 'product-designer' ); ?> <span class="pd-side-tab__count" hidden>0</span></button>
                    <button type="button" class="pd-side-tab" data-side="back" role="tab" aria-selected="false"><?php esc_html_e( 'Spate', 'product-designer' ); ?> <span class="pd-side-tab__count" hidden>0</span></button>
                </div>
                <button type="button" class="pd-tool pd-add-text"><?php esc_html_e( 'Adaugă text', 'product-designer' ); ?></button>
                <label class="pd-tool pd-upload-btn">
                    <?php esc_html_e( 'Încarcă imagine', 'product-designer' ); ?>
                    <input type="file" class="pd-upload-input" accept="image/png,image/jpeg,image/webp" hidden />
                </label>
                <button type="button" class="pd-tool pd-delete" disabled><?php esc_html_e( 'Șterge selecția', 'product-designer' ); ?></button>

                <div class="pd-text-controls" hidden>
                    <div class="pd-text-controls__title"><?php esc_html_e( 'Proprietăți text', 'product-designer' ); ?></div>
                    <label class="pd-control-label">
                        <?php esc_html_e( 'Culoare', 'product-designer' ); ?>
                        <input type="color" class="pd-text-color" value="#111111" />
                    </label>
                    <label class="pd-control-label">
                        <?php esc_html_e( 'Mărime (px)', 'product-designer' ); ?>
                        <input type="number" class="pd-text-size" min="8" max="600" step="1" value="48" />
                    </label>
                    <label class="pd-control-label">
                        <?php esc_html_e( 'Font', 'product-designer' ); ?>
                        <select class="pd-text-font">
                            <option value="Arial">Arial</option>
                            <option value="Helvetica">Helvetica</option>
                            <option value="Times New Roman">Times New Roman</option>
                            <option value="Georgia">Georgia</option>
                            <option value="Courier New">Courier New</option>
                            <option value="Verdana">Verdana</option>
                            <option value="Tahoma">Tahoma</option>
                            <option value="Trebuchet MS">Trebuchet MS</option>
                            <option value="Impact">Impact</option>
                            <option value="Comic Sans MS">Comic Sans MS</option>
                        </select>
                    </label>
                    <div class="pd-control-row">
                        <button type="button" class="pd-tool-small pd-text-bold" title="<?php esc_attr_e( 'Bold', 'product-designer' ); ?>"><strong>B</strong></button>
                        <button type="button" class="pd-tool-small pd-text-italic" title="<?php esc_attr_e( 'Italic', 'product-designer' ); ?>"><em>I</em></button>
                        <button type="button" class="pd-tool-small pd-text-underline" title="<?php esc_attr_e( 'Underline', 'product-designer' ); ?>"><u>U</u></button>
                    </div>
                    <label class="pd-control-label">
                        <?php esc_html_e( 'Aliniere', 'product-designer' ); ?>
                        <select class="pd-text-align">
                            <option value="left"><?php esc_html_e( 'Stânga', 'product-designer' ); ?></option>
                            <option value="center"><?php esc_html_e( 'Centru', 'product-designer' ); ?></option>
                            <option value="right"><?php esc_html_e( 'Dreapta', 'product-designer' ); ?></option>
                        </select>
                    </label>
                </div>

                <div class="pd-status" aria-live="polite"></div>
            </aside>

            <!-- Canvas area -->
            <div class="pd-canvas-wrap">
                <canvas class="pd-canvas pd-canvas--front" data-side="front"></canvas>
                <canvas class="pd-canvas pd-canvas--back"  data-side="back" hidden></canvas>
                <button type="button" class="pd-zoom-reset" hidden aria-label="<?php esc_attr_e( 'Resetează zoom', 'product-designer' ); ?>" title="<?php esc_attr_e( 'Resetează zoom', 'product-designer' ); ?>">&#x2296;</button>
            </div>

            <!-- Sidebar dreapta: produs ales + add to cart -->
            <aside class="pd-summary">
                <div class="pd-summary__product">
                    <img class="pd-summary__thumb" src="" alt="" />
                    <h3 class="pd-summary__name" data-pd-summary-name></h3>
                    <div class="pd-summary__price" data-pd-summary-price></div>
                </div>

                <div class="pd-summary__actions">
                    <button type="button" class="pd-tool pd-save"><?php esc_html_e( 'Salvează designul', 'product-designer' ); ?></button>
                    <button type="button" class="pd-add-to-cart-final" disabled>
                        <?php esc_html_e( 'Adaugă în coș', 'product-designer' ); ?>
                    </button>
                    <p class="pd-summary__hint" data-pd-summary-hint><?php esc_html_e( 'Salvează designul pentru a continua.', 'product-designer' ); ?></p>
                </div>

                <!-- Hidden inputs pentru add-to-cart (folosite de constructor.js la submit) -->
                <input type="hidden" class="pd-design-id"        value="" />
                <input type="hidden" class="pd-preview-url"      value="" />
                <input type="hidden" class="pd-preview-back-url" value="" />
                <input type="hidden" class="pd-json-url"         value="" />
                <div class="pd-selected-preview" hidden>
                    <img src="" alt="" />
                    <button type="button" class="pd-clear-design"><?php esc_html_e( 'Elimină design', 'product-designer' ); ?></button>
                </div>
            </aside>

            <!-- Bottom-bar — apare DOAR pe mobile (≤900px) via CSS.
                 Acțiuni rapide pentru editor + acces la sheet-uri (text, summary). -->
            <nav class="pd-bottom-bar" role="toolbar" aria-label="<?php esc_attr_e( 'Editor — acțiuni', 'product-designer' ); ?>">
                <button type="button" class="pd-bb-btn pd-bb-add-text" data-pd-action="add-text">
                    <span class="pd-bb-btn__icon" aria-hidden="true">A+</span>
                    <span class="pd-bb-btn__label"><?php esc_html_e( 'Text', 'product-designer' ); ?></span>
                </button>
                <button type="button" class="pd-bb-btn pd-bb-add-image" data-pd-action="add-image">
                    <span class="pd-bb-btn__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5" fill="currentColor" stroke="none"/><path d="m21 16-5-5-9 9"/></svg>
                    </span>
                    <span class="pd-bb-btn__label"><?php esc_html_e( 'Imagine', 'product-designer' ); ?></span>
                </button>
                <button type="button" class="pd-bb-btn pd-bb-delete" data-pd-action="delete" disabled>
                    <span class="pd-bb-btn__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                    </span>
                    <span class="pd-bb-btn__label"><?php esc_html_e( 'Șterge', 'product-designer' ); ?></span>
                </button>
                <button type="button" class="pd-bb-btn pd-bb-edit-text" data-pd-sheet="text" hidden>
                    <span class="pd-bb-btn__icon" aria-hidden="true">Aa</span>
                    <span class="pd-bb-btn__label"><?php esc_html_e( 'Editează', 'product-designer' ); ?></span>
                </button>
                <button type="button" class="pd-bb-btn pd-bb-toggle-side" hidden>
                    <span class="pd-bb-btn__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M7 7l-3 3 3 3M17 17l3-3-3-3M4 10h12M20 14H8"/></svg>
                    </span>
                    <span class="pd-bb-btn__label"><?php esc_html_e( 'Spate', 'product-designer' ); ?></span>
                </button>
                <button type="button" class="pd-bb-btn pd-bb-summary" data-pd-sheet="summary">
                    <span class="pd-bb-btn__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 5h13"/><circle cx="9" cy="20" r="1.5" fill="currentColor" stroke="none"/><circle cx="18" cy="20" r="1.5" fill="currentColor" stroke="none"/></svg>
                    </span>
                    <span class="pd-bb-btn__label"><?php esc_html_e( 'Comandă', 'product-designer' ); ?></span>
                </button>
            </nav>
        </div>
    </section>

</div>
