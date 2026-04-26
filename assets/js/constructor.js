/* global jQuery, PDConstructorData, PDDesigner */
/**
 * Product Designer — Constructor wizard.
 *
 * Orchestrează flow-ul step 1 (categorii) → step 2 (produse) → step 3 (designer)
 * → add-to-cart. designer.js rămâne neschimbat — primește PDData via event `pd:mount`.
 */
(function ($) {
    'use strict';

    if (typeof PDConstructorData === 'undefined') {
        if (window.console) { console.error('[PDC] PDConstructorData missing — wp_localize_script did not run.'); }
        return;
    }

    var $root           = $('.pd-constructor');
    var $progressSteps  = $root.find('.pd-progress__step');
    var $panels         = $root.find('[data-panel]');
    var $categories     = $root.find('[data-pd-categories]');
    var $products       = $root.find('[data-pd-products]');
    var $variations     = $root.find('[data-pd-variations]');
    var $variationActions = $root.find('[data-pd-variation-actions]');
    var $btnContinue    = $root.find('.pd-continue-to-design');
    var $crumbCat       = $root.find('[data-pd-current-category]');
    var $crumbProd      = $root.find('[data-pd-current-product]');
    var $crumbProd2     = $root.find('[data-pd-current-product-2]');
    var $summaryName    = $root.find('[data-pd-summary-name]');
    var $summaryPrice   = $root.find('[data-pd-summary-price]');
    var $summaryThumb   = $root.find('.pd-summary__thumb');
    var $summaryHint    = $root.find('[data-pd-summary-hint]');
    var $btnAddCart     = $root.find('.pd-add-to-cart-final');
    var $hiddenId       = $root.find('.pd-design-id');

    var state = {
        categoryId:    null,
        categoryName:  '',
        productId:     null,
        productName:   '',
        productThumb:  '',
        productPrice:  '',
        productVariable: false,
        productPermalink: '',
        // Variations state
        attributes:    [],   // [{name, label, options:[{value,label}]}]
        variations:    [],   // [{id, attributes, price_html, is_in_stock, mockup_url}]
        chosenAttrs:   {},   // {pa_size: 'm', pa_color: 'red'}
        variationId:   0,
        // Design state
        designId:      null
    };

    // ---------- State helpers ----------

    function setStep(n) {
        // n poate fi 1, 2, 2.5 (variation picker), 3.
        $root.attr('data-step', String(n));
        $panels.each(function () {
            var panel = $(this);
            var target = panel.attr('data-panel'); // string ca să gestionăm "2.5"
            panel.prop('hidden', target !== String(n));
        });
        // Progress bar — 2.5 mapat la step 2 (vizual).
        var progressN = (n === 2.5 || n === '2.5') ? 2 : parseInt(n, 10);
        $progressSteps.each(function () {
            var step = $(this);
            var target = parseInt(step.attr('data-target'), 10);
            step.toggleClass('is-active', target === progressN);
            step.toggleClass('is-done', target < progressN);
        });
        // Scroll into view smooth pe mobile.
        if (window.innerWidth < 720) {
            $root[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function setBusy(busy) {
        $root.attr('aria-busy', busy ? 'true' : 'false');
    }

    function fmtCount(n) {
        n = parseInt(n, 10) || 0;
        if (n === 1) {
            return PDConstructorData.i18n.productCount1;
        }
        return PDConstructorData.i18n.productsCount.replace('%d', String(n));
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ---------- REST helpers ----------

    function apiGet(path, params) {
        var url = PDConstructorData.rest.root + path;
        return $.ajax({
            url: url,
            method: 'GET',
            data: params || {},
            headers: { 'X-WP-Nonce': PDConstructorData.rest.nonce },
            dataType: 'json'
        });
    }

    // ---------- Step 1: categorii ----------

    function loadCategories() {
        setBusy(true);
        $categories.html('<p class="pd-loading">' + escapeHtml(PDConstructorData.i18n.loading) + '</p>');
        apiGet('constructor/categories').done(function (res) {
            renderCategories(res && res.items ? res.items : []);
        }).fail(function () {
            $categories.html('<p class="pd-error">' + escapeHtml(PDConstructorData.i18n.errorLoad) + '</p>');
        }).always(function () {
            setBusy(false);
        });
    }

    function renderCategories(items) {
        if (!items.length) {
            $categories.html('<p class="pd-empty">' + escapeHtml(PDConstructorData.i18n.noProducts) + '</p>');
            return;
        }
        var html = '<div class="pd-cards">';
        items.forEach(function (cat) {
            var icon = cat.icon_url
                ? '<img class="pd-card__icon" src="' + escapeHtml(cat.icon_url) + '" alt="" />'
                : '<span class="pd-card__icon pd-card__icon--placeholder" aria-hidden="true">📦</span>';
            html += '<button type="button" class="pd-card pd-card--category" data-id="' + cat.id + '" data-name="' + escapeHtml(cat.name) + '">'
                  +     icon
                  +    '<span class="pd-card__name">' + escapeHtml(cat.name) + '</span>'
                  +    '<span class="pd-card__meta">' + escapeHtml(fmtCount(cat.count)) + '</span>'
                  + '</button>';
        });
        html += '</div>';
        $categories.html(html);
    }

    // ---------- Step 2: produse ----------

    function loadProducts(categoryId, categoryName) {
        state.categoryId   = categoryId;
        state.categoryName = categoryName;
        $crumbCat.text(categoryName);
        setBusy(true);
        $products.html('<p class="pd-loading">' + escapeHtml(PDConstructorData.i18n.loading) + '</p>');
        setStep(2);

        apiGet('constructor/products', { category_id: categoryId, per_page: 24 }).done(function (res) {
            renderProducts(res && res.items ? res.items : []);
        }).fail(function () {
            $products.html('<p class="pd-error">' + escapeHtml(PDConstructorData.i18n.errorLoad) + '</p>');
        }).always(function () {
            setBusy(false);
        });
    }

    function renderProducts(items) {
        if (!items.length) {
            $products.html('<p class="pd-empty">' + escapeHtml(PDConstructorData.i18n.noProducts) + '</p>');
            return;
        }
        var html = '<div class="pd-cards pd-cards--products">';
        items.forEach(function (p) {
            var thumb = p.thumbnail_url
                ? '<img class="pd-card__thumb" src="' + escapeHtml(p.thumbnail_url) + '" alt="" loading="lazy" />'
                : '<span class="pd-card__thumb pd-card__thumb--placeholder" aria-hidden="true"></span>';
            // p.price_html conține deja <span class="woocommerce-Price-amount"> etc., îl tratăm safe via escape de atribute.
            var dataset = ' data-id="' + p.id + '"'
                        + ' data-name="' + escapeHtml(p.name) + '"'
                        + ' data-thumb="' + escapeHtml(p.thumbnail_url || '') + '"'
                        + ' data-price="' + escapeHtml(p.price_html || '') + '"'
                        + ' data-variable="' + (p.is_variable ? '1' : '0') + '"'
                        + ' data-permalink="' + escapeHtml(p.permalink || '') + '"';
            html += '<button type="button" class="pd-card pd-card--product"' + dataset + '>'
                  +     thumb
                  +    '<span class="pd-card__name">' + escapeHtml(p.name) + '</span>'
                  +    '<span class="pd-card__price">' + p.price_html + '</span>'
                  + '</button>';
        });
        html += '</div>';
        $products.html(html);
    }

    // ---------- Step 2.5: variation picker (doar pentru produse variabile) ----------

    function pickProduct(productData) {
        state.productId        = parseInt(productData.id, 10);
        state.productName      = productData.name || '';
        state.productThumb     = productData.thumb || '';
        state.productPrice     = productData.price || '';
        state.productVariable  = productData.variable === '1';
        state.productPermalink = productData.permalink || '';
        state.designId         = null;
        state.variationId      = 0;
        state.chosenAttrs      = {};
        state.attributes       = [];
        state.variations       = [];

        $crumbProd.text(state.productName);
        $crumbProd2.text(state.productName);

        if (state.productVariable) {
            loadVariations();
        } else {
            mountDesigner();
        }
    }

    function loadVariations() {
        setBusy(true);
        $variations.html('<p class="pd-loading">' + escapeHtml(PDConstructorData.i18n.loading) + '</p>');
        $variationActions.prop('hidden', true);
        $btnContinue.prop('disabled', true);
        setStep(2.5);

        apiGet('constructor/variations', { product_id: state.productId }).done(function (res) {
            state.attributes = res && res.attributes ? res.attributes : [];
            state.variations = res && res.variations ? res.variations : [];
            renderVariations();
        }).fail(function () {
            $variations.html('<p class="pd-error">' + escapeHtml(PDConstructorData.i18n.errorLoad) + '</p>');
        }).always(function () {
            setBusy(false);
        });
    }

    function renderVariations() {
        if (!state.attributes.length) {
            $variations.html('<p class="pd-empty">' + escapeHtml(PDConstructorData.i18n.noProducts) + '</p>');
            return;
        }
        var html = '<div class="pd-variation-attrs">';
        state.attributes.forEach(function (attr) {
            html += '<div class="pd-variation-attr">'
                  +    '<label class="pd-variation-attr__label">' + escapeHtml(attr.label) + '</label>'
                  +    '<div class="pd-variation-attr__options" data-attr-name="' + escapeHtml(attr.name) + '">';
            attr.options.forEach(function (opt) {
                html += '<button type="button" class="pd-variation-option" '
                      +     'data-attr="' + escapeHtml(attr.name) + '" '
                      +     'data-value="' + escapeHtml(opt.value) + '">'
                      +    escapeHtml(opt.label)
                      + '</button>';
            });
            html += '</div></div>';
        });
        html += '</div>';
        $variations.html(html);
        $variationActions.prop('hidden', false);
    }

    function onAttributePick($btn) {
        var attr = $btn.attr('data-attr');
        var val  = $btn.attr('data-value');
        state.chosenAttrs[attr] = val;

        // UI: marchează toate opțiunile pentru acest atribut ca inactiv, alta ca active.
        $variations.find('[data-attr-name="' + attr + '"] .pd-variation-option').removeClass('is-active');
        $btn.addClass('is-active');

        // Resolve variation_id pe client (matching toate atributele).
        var match = matchVariation(state.chosenAttrs);
        if (match) {
            state.variationId = match.id;
            $btnContinue.prop('disabled', false).text(PDConstructorData.i18n.customize + ' (' + (match.price_html ? stripHtml(match.price_html) : '') + ')');
        } else {
            // Nu sunt toate atributele alese încă, sau nu există variație în stoc.
            state.variationId = 0;
            $btnContinue.prop('disabled', true).text(PDConstructorData.i18n.customize);
        }
    }

    function matchVariation(chosen) {
        // Returnează prima variație ale cărei attributes sunt match cu chosen
        // (variation attributes pot avea valori goale care înseamnă „any").
        return state.variations.find(function (v) {
            for (var k in chosen) {
                if (!Object.prototype.hasOwnProperty.call(chosen, k)) { continue; }
                var key = 'attribute_' + k;
                var vAttr = v.attributes && v.attributes[key];
                if (vAttr && vAttr !== '' && vAttr !== chosen[k]) {
                    return false;
                }
            }
            // Toate atributele variation să fie acoperite (sau goale = any).
            if (v.attributes) {
                for (var ak in v.attributes) {
                    if (!Object.prototype.hasOwnProperty.call(v.attributes, ak)) { continue; }
                    var clean = ak.replace(/^attribute_/, '');
                    if (v.attributes[ak] && !chosen[clean]) {
                        return false;
                    }
                }
            }
            return v.is_in_stock !== false;
        });
    }

    function stripHtml(s) {
        var tmp = document.createElement('div');
        tmp.innerHTML = s;
        return (tmp.textContent || tmp.innerText || '').trim();
    }

    // ---------- Step 3: designer ----------

    function mountDesigner() {
        // UI summary
        $summaryName.text(state.productName);
        $summaryPrice.html(state.productPrice);
        if (state.productThumb) {
            $summaryThumb.attr('src', state.productThumb).show();
        } else {
            $summaryThumb.hide();
        }

        // Reset add-to-cart.
        $btnAddCart.prop('disabled', true).text(PDConstructorData.i18n.addToCart);
        $summaryHint.text(PDConstructorData.i18n.saveFirst).show();

        setStep(3);

        var params = { product_id: state.productId };
        if (state.variationId) { params.variation_id = state.variationId; }

        setBusy(true);
        apiGet('constructor/product-config', params).done(function (config) {
            document.dispatchEvent(new CustomEvent('pd:mount', { detail: config }));
        }).fail(function () {
            alert(PDConstructorData.i18n.errorLoad);
            setStep(state.productVariable ? 2.5 : 2);
        }).always(function () {
            setBusy(false);
        });
    }

    // ---------- Save design wiring ----------

    // designer.js setează $hiddenId.val() la save success. Polăm valoarea
    // ca să activăm butonul „Adaugă în coș" automat.
    var pollSaveTimer = null;
    function watchDesignSave() {
        if (pollSaveTimer) { clearInterval(pollSaveTimer); }
        pollSaveTimer = setInterval(function () {
            if ($root.attr('data-step') !== '3') { return; }
            var id = $hiddenId.val();
            if (id && id !== state.designId) {
                state.designId = id;
                $btnAddCart.prop('disabled', false);
                $summaryHint.hide();
            }
            if (!id && state.designId) {
                state.designId = null;
                $btnAddCart.prop('disabled', true);
                $summaryHint.text(PDConstructorData.i18n.saveFirst).show();
            }
        }, 400);
    }

    // ---------- Add to cart ----------

    function addToCart() {
        if (!state.productId || !state.designId) { return; }
        if (state.productVariable && !state.variationId) {
            alert(PDConstructorData.i18n.errorLoad);
            return;
        }

        $btnAddCart.prop('disabled', true).text(PDConstructorData.i18n.addingToCart);

        var $form = $('<form>', {
            method: 'post',
            action: PDConstructorData.cart.addToCartUrl
        }).appendTo('body');

        function addInput(name, value) {
            if (value === undefined || value === null) { value = ''; }
            $('<input>', { type: 'hidden', name: name, value: String(value) }).appendTo($form);
        }
        addInput('add-to-cart', state.productId);
        addInput('quantity', 1);

        // Variation: pentru produse variabile, WC așteaptă variation_id
        // + valorile atributelor cu nume `attribute_X`.
        if (state.productVariable && state.variationId) {
            addInput('variation_id', state.variationId);
            for (var k in state.chosenAttrs) {
                if (Object.prototype.hasOwnProperty.call(state.chosenAttrs, k)) {
                    addInput('attribute_' + k, state.chosenAttrs[k]);
                }
            }
        }

        addInput('pd_design_id',        state.designId);
        addInput('pd_preview_url',      $root.find('.pd-preview-url').val());
        addInput('pd_preview_back_url', $root.find('.pd-preview-back-url').val());
        addInput('pd_json_url',         $root.find('.pd-json-url').val());
        addInput('pd_from_constructor', '1');

        $form.trigger('submit');
    }

    // ---------- Event wiring ----------

    $root.on('click', '.pd-card--category', function () {
        var $btn = $(this);
        loadProducts(parseInt($btn.attr('data-id'), 10), $btn.attr('data-name') || '');
    });

    $root.on('click', '.pd-card--product', function () {
        var $btn = $(this);
        pickProduct({
            id:        $btn.attr('data-id'),
            name:      $btn.attr('data-name'),
            thumb:     $btn.attr('data-thumb'),
            price:     $btn.attr('data-price'),
            variable:  $btn.attr('data-variable'),
            permalink: $btn.attr('data-permalink')
        });
    });

    $root.on('click', '.pd-variation-option', function () {
        onAttributePick($(this));
    });

    $root.on('click', '.pd-continue-to-design', function () {
        if (!state.variationId) { return; }
        mountDesigner();
    });

    $root.on('click', '[data-pd-back-to-step]', function () {
        var n = parseFloat($(this).attr('data-pd-back-to-step'));
        var current = parseFloat($root.attr('data-step'));

        // Reset designer state când părăsim step 3.
        if (current === 3 && n < 3) {
            $hiddenId.val('');
            $root.find('.pd-preview-url').val('');
            $root.find('.pd-json-url').val('');
            state.designId = null;
            $btnAddCart.prop('disabled', true).text(PDConstructorData.i18n.addToCart);
            $summaryHint.text(PDConstructorData.i18n.saveFirst).show();
        }
        // Reset variation state când părăsim step 2.5 înapoi la 2.
        if (current === 2.5 && n <= 2) {
            state.variationId = 0;
            state.chosenAttrs = {};
        }
        if (n === 1) {
            state.categoryId = null;
            state.productId = null;
        }
        setStep(n);
    });

    $btnAddCart.on('click', addToCart);

    // ---------- Init ----------

    $(function () {
        loadCategories();
        watchDesignSave();
    });
})(jQuery);
