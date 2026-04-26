/* global jQuery, fabric */
/**
 * Product Designer — Fabric.js frontend editor (Față / Spate).
 *
 * PDData shape:
 *   rest.{root,nonce,uploadPath,designPath}
 *   product.{id, mockup_front_url, mockup_back_url}
 *   canvas.{width,height}                  — fallback când produsul n-are mockup
 *   limits.{maxUploadMB, mimeTypes}
 *   i18n.{..., sideFront, sideBack}
 *
 * PDData provine din:
 *   - `wp_localize_script` pe single-product (auto-boot)
 *   - constructor.js care emite event `pd:mount` cu detail = PDData
 *
 * Architecture:
 *   - Două instanțe Fabric.Canvas, una per parte (`front` / `back`).
 *   - Doar una vizibilă la rândul ei; switching-ul între tab-uri e doar
 *     toggle de vizibilitate (mockup-urile rămân încărcate).
 *   - Tab-urile apar DOAR dacă produsul are AMBELE mockup-uri configurate.
 *   - La save, ambele canvas-uri sunt serializate într-un singur JSON v2
 *     `{front:{...}, back:{...}}` și fiecare parte populată produce un PNG separat.
 */
(function ($) {
    'use strict';

    if (typeof fabric === 'undefined') {
        if (window.console) { console.error('[PD] fabric.js failed to load.'); }
        $(function () {
            $(document).on('click', '.pd-open-designer', function (e) {
                e.preventDefault();
                alert('Editor-ul nu poate fi încărcat (fabric.js lipsește). Verifică consola.');
            });
        });
        return;
    }

    // PDData mutabil intern — poate fi schimbat de constructor la fiecare produs.
    var PDData = (typeof window.PDData !== 'undefined') ? window.PDData : null;

    // Cap rezoluția internă a canvas-ului ca să nu explodeze RAM-ul / preview-ul
    // PNG la mockup-uri uriașe (4K+).
    var MAX_INTERNAL_DIM = 2000;

    var SIDES = ['front', 'back'];

    // State per parte: instanța Fabric, dimensiunile native cap-uite.
    var canvases = { front: null, back: null };
    var activeSide = 'front';
    var availableSides = ['front']; // setat la boot din PDData

    // Selectorii sunt re-cache-uiți la fiecare boot.
    var $modal, $status, $deleteBtn, $hiddenId, $hiddenPng, $hiddenPngBack, $hiddenJson, $selPrev;
    var $textCtrls, $textColor, $textSize, $textFont, $textBold, $textItalic, $textUnderline, $textAlign;
    var $sideTabs;

    function cacheSelectors() {
        $modal         = $('.pd-modal');
        $status        = $('.pd-status');
        $deleteBtn     = $('.pd-delete');
        $hiddenId      = $('.pd-design-id');
        $hiddenPng     = $('.pd-preview-url');
        $hiddenPngBack = $('.pd-preview-back-url');
        $hiddenJson    = $('.pd-json-url');
        $selPrev       = $('.pd-selected-preview');
        $textCtrls     = $('.pd-text-controls');
        $textColor     = $('.pd-text-color');
        $textSize      = $('.pd-text-size');
        $textFont      = $('.pd-text-font');
        $textBold      = $('.pd-text-bold');
        $textItalic    = $('.pd-text-italic');
        $textUnderline = $('.pd-text-underline');
        $textAlign     = $('.pd-text-align');
        $sideTabs      = $('.pd-side-tabs');
    }
    cacheSelectors();

    function say(msg) { $status.text(msg || ''); }

    function sameOrigin(url) {
        try { return new URL(url, window.location.href).origin === window.location.origin; }
        catch (e) { return true; }
    }

    function activeCanvas() { return canvases[activeSide]; }

    // ==========================================================================
    // Modal lifecycle
    // ==========================================================================

    function openModal() {
        $modal.prop('hidden', false).attr('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        // Inițializează lazy canvas-urile la prima deschidere.
        availableSides.forEach(function (side) {
            if (!canvases[side]) { initCanvasForSide(side); }
        });
        scheduleRefit();
        observeWrap();
    }
    function closeModal() {
        $modal.prop('hidden', true).attr('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    // ==========================================================================
    // Canvas initialization (per side)
    // ==========================================================================

    function initCanvasForSide(side) {
        var canvasEl = document.querySelector('.pd-canvas[data-side="' + side + '"]');
        if (!canvasEl) {
            console.error('[PD] Canvas element pentru "' + side + '" nu există în DOM.');
            return;
        }
        // CRITIC: scoate `hidden` ÎNAINTE de Fabric init. Altfel Fabric păstrează
        // atributul pe lower-canvas (unde se desenează mockup-ul) și chiar dacă
        // ascundem/arătăm containerul ulterior, lower-canvas rămâne display:none —
        // user-ul vede doar upper-canvas (transparent) → tab-ul pare gol.
        canvasEl.hidden = false;
        canvasEl.removeAttribute('hidden');

        var c = new fabric.Canvas(canvasEl, {
            preserveObjectStacking: true,
            backgroundColor: '#fff',
            // Fabric 5.x bug: cu cssOnly + retina pe HiDPI, click-urile nu nimeresc.
            enableRetinaScaling: false
        });
        c.pdSide = side;

        // Vizibilitate gestionată pe `.canvas-container` (părintele creat de Fabric),
        // NU pe lower-canvas. Se sincronizează cu `activeSide`.
        applyContainerVisibility(side);

        c.setDimensions({
            width:  PDData.canvas.width,
            height: PDData.canvas.height
        });

        c.on('selection:created', onSelection);
        c.on('selection:updated', onSelection);
        c.on('selection:cleared', function () {
            $deleteBtn.prop('disabled', true);
            $textCtrls.prop('hidden', true);
        });
        // Update tab counter când user-ul adaugă / șterge.
        c.on('object:added',   function () { updateSideCount(side); });
        c.on('object:removed', function () { updateSideCount(side); });

        canvases[side] = c;

        var url = (side === 'front') ? PDData.product.mockup_front_url : PDData.product.mockup_back_url;
        if (url) {
            loadMockupForSide(side, url);
        } else {
            c.renderAll();
        }
    }

    function loadMockupForSide(side, url) {
        var native = new Image();
        if (!sameOrigin(url)) { native.crossOrigin = 'anonymous'; }

        native.onload = function () {
            var c = canvases[side];
            if (!c) { return; }
            var nw = native.naturalWidth, nh = native.naturalHeight;
            if (!nw || !nh) {
                console.error('[PD] Mockup ' + side + ' invalid:', url);
                say('Mockup invalid.');
                c.renderAll();
                return;
            }

            var cap = Math.min(MAX_INTERNAL_DIM / nw, MAX_INTERNAL_DIM / nh, 1);
            var w = Math.round(nw * cap);
            var h = Math.round(nh * cap);

            if (window.console) {
                console.log('[PD] Mockup ' + side + ' încărcat:', url,
                    'native ' + nw + 'x' + nh +
                    (cap < 1 ? ' → canvas redus la ' + w + 'x' + h + ' (cap ' + MAX_INTERNAL_DIM + 'px)' : ''));
            }

            c.setDimensions({ width: w, height: h });

            var fImg = new fabric.Image(native, {
                left: 0, top: 0,
                scaleX: cap, scaleY: cap,
                originX: 'left', originY: 'top',
                selectable: false,
                evented: false,
                hoverCursor: 'default',
                excludeFromExport: true
            });
            fImg.pdMockup = true;
            c.add(fImg);
            c.sendToBack(fImg);

            if (side === activeSide) {
                fitCanvasToWrap(w, h);
                scheduleRefit();
            }
            c.renderAll();
            observeWrap();
        };
        native.onerror = function () {
            console.error('[PD] Mockup ' + side + ' n-a putut fi încărcat:', url);
            say('Mockup nu s-a putut încărca. Verifică consola.');
            if (canvases[side]) { canvases[side].renderAll(); }
        };
        native.src = url;
    }

    // ==========================================================================
    // Side switching
    // ==========================================================================

    /**
     * Toggle visibility of one side's `.canvas-container` (the wrapper Fabric
     * creates around lower-canvas + upper-canvas). NEVER touch the inner canvas
     * `hidden` attribute — Fabric preserves it on lower-canvas and that hides
     * the actual drawing surface.
     */
    function applyContainerVisibility(side) {
        var canvasEl = document.querySelector('.pd-canvas[data-side="' + side + '"]');
        if (!canvasEl) { return; }
        // Defensive: if canvas was init-uit cu hidden și Fabric l-a păstrat pe
        // lower-canvas, scoate-l acum.
        canvasEl.hidden = false;
        canvasEl.removeAttribute('hidden');

        var container = (canvasEl.parentNode && canvasEl.parentNode.classList && canvasEl.parentNode.classList.contains('canvas-container'))
            ? canvasEl.parentNode
            : canvasEl;
        container.style.display = (side === activeSide) ? '' : 'none';
    }

    function setActiveSide(side) {
        if (SIDES.indexOf(side) === -1 || side === activeSide) { return; }
        if (availableSides.indexOf(side) === -1) { return; }

        var prev = activeSide;
        activeSide = side;

        // Curăță selecția pe canvas-ul vechi (UI controls referă obiectul activ).
        if (canvases[prev]) {
            canvases[prev].discardActiveObject();
            canvases[prev].requestRenderAll();
        }
        $deleteBtn.prop('disabled', true);
        $textCtrls.prop('hidden', true);

        // Toggle vizibilitate pe wrapper-ele Fabric (.canvas-container).
        SIDES.forEach(applyContainerVisibility);

        // Update tab UI.
        $sideTabs.find('.pd-side-tab').each(function () {
            var $t = $(this);
            var isActive = $t.attr('data-side') === activeSide;
            $t.toggleClass('is-active', isActive).attr('aria-selected', isActive ? 'true' : 'false');
        });

        // Forțează un re-render pe canvas-ul nou activ (browser-ele uneori nu
        // re-paint-ează lower-canvas când trece din display:none la visible).
        if (canvases[activeSide]) {
            canvases[activeSide].requestRenderAll();
        }

        // Refit canvas-ul nou la wrap (poate a fost ascuns când era inactiv → wrap scaling stale).
        scheduleRefit();
    }

    function updateSideCount(side) {
        var c = canvases[side];
        if (!c) { return; }
        var count = c.getObjects().filter(function (o) { return !o.excludeFromExport; }).length;
        var $tab = $sideTabs.find('.pd-side-tab[data-side="' + side + '"]');
        var $badge = $tab.find('.pd-side-tab__count');
        if (count > 0) {
            $badge.text(count).prop('hidden', false);
        } else {
            $badge.text('0').prop('hidden', true);
        }
    }

    // ==========================================================================
    // Layout / resize
    // ==========================================================================

    function fitCanvasToWrap(innerW, innerH) {
        var wrap = $('.pd-canvas-wrap')[0];
        var c = activeCanvas();
        if (!wrap || !c) { return; }
        innerW = innerW || c.getWidth();
        innerH = innerH || c.getHeight();
        if (!innerW || !innerH) { return; }
        var padding = 32;
        var availW = Math.max(100, wrap.clientWidth  - padding);
        var availH = Math.max(100, wrap.clientHeight - padding);
        var scale  = Math.min(availW / innerW, availH / innerH, 1);
        c.setDimensions({
            width:  Math.round(innerW * scale),
            height: Math.round(innerH * scale)
        }, { cssOnly: true });
    }

    function scheduleRefit() {
        if (!activeCanvas()) { return; }
        var raf = window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); };
        raf(function () {
            raf(function () {
                fitCanvasToWrap();
            });
        });
    }

    var wrapObserver = null;
    var wrapObserverTimer = null;
    function observeWrap() {
        if (typeof window.ResizeObserver === 'undefined') { return; }
        var wrap = document.querySelector('.pd-canvas-wrap');
        if (!wrap) { return; }
        if (wrapObserver) {
            try { wrapObserver.disconnect(); } catch (e) { /* ignore */ }
        }
        wrapObserver = new ResizeObserver(function () {
            if (!activeCanvas()) { return; }
            clearTimeout(wrapObserverTimer);
            wrapObserverTimer = setTimeout(function () {
                fitCanvasToWrap();
            }, 80);
        });
        wrapObserver.observe(wrap);
    }

    // ==========================================================================
    // Selection / text controls
    // ==========================================================================

    function onSelection() {
        var c = activeCanvas();
        if (!c) { return; }
        var obj = c.getActiveObject();
        $deleteBtn.prop('disabled', !obj);
        syncTextControls(obj);
    }

    function isTextObject(obj) {
        return !!obj && (obj.type === 'i-text' || obj.type === 'text' || obj.type === 'textbox');
    }

    function syncTextControls(obj) {
        if (!isTextObject(obj)) {
            $textCtrls.prop('hidden', true);
            return;
        }
        $textCtrls.prop('hidden', false);
        $textColor.val(normalizeColor(obj.fill) || '#111111');
        $textSize.val(Math.round(obj.fontSize || 48));
        $textFont.val(obj.fontFamily || 'Arial');
        $textAlign.val(obj.textAlign || 'left');
        $textBold.toggleClass('is-active', obj.fontWeight === 'bold' || obj.fontWeight >= 600);
        $textItalic.toggleClass('is-active', obj.fontStyle === 'italic');
        $textUnderline.toggleClass('is-active', !!obj.underline);
    }

    function normalizeColor(value) {
        if (!value || typeof value !== 'string') { return ''; }
        if (value.charAt(0) === '#' && value.length === 7) { return value; }
        var tmp = document.createElement('div');
        tmp.style.color = value;
        document.body.appendChild(tmp);
        var rgb = getComputedStyle(tmp).color;
        document.body.removeChild(tmp);
        var m = /^rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(rgb);
        if (!m) { return ''; }
        var toHex = function (n) { var h = parseInt(n, 10).toString(16); return h.length === 1 ? '0' + h : h; };
        return '#' + toHex(m[1]) + toHex(m[2]) + toHex(m[3]);
    }

    function applyToActiveText(mutator) {
        var c = activeCanvas();
        if (!c) { return; }
        var obj = c.getActiveObject();
        if (!isTextObject(obj)) { return; }
        mutator(obj);
        obj.setCoords();
        c.requestRenderAll();
    }

    function defaultSpawnArea() {
        var c = activeCanvas();
        var canvasW = c ? c.getWidth()  : 600;
        var canvasH = c ? c.getHeight() : 700;
        var w = Math.round(canvasW * 0.6);
        var h = Math.round(canvasH * 0.6);
        return { x: Math.round((canvasW - w) / 2), y: Math.round((canvasH - h) / 2), width: w, height: h };
    }

    // ==========================================================================
    // Tools — operate on active canvas
    // ==========================================================================

    function addText() {
        var c = activeCanvas();
        if (!c) { console.warn('[PD] addText: canvas not ready yet.'); return; }
        try {
            var a = defaultSpawnArea();
            var sizeFromCtrl = parseInt($textSize.val(), 10);
            var fontSize = sizeFromCtrl >= 8
                ? sizeFromCtrl
                : Math.max(24, Math.round(a.height * 0.25));

            var t = new fabric.IText(PDData.i18n.placeholder || 'Textul tău aici', {
                left: a.x + 10,
                top:  a.y + 10,
                fontSize: fontSize,
                fill: $textColor.val() || '#111111',
                fontFamily: $textFont.val() || 'Arial',
                textAlign: $textAlign.val() || 'left',
                fontWeight: $textBold.hasClass('is-active') ? 'bold' : 'normal',
                fontStyle: $textItalic.hasClass('is-active') ? 'italic' : 'normal',
                underline: $textUnderline.hasClass('is-active'),
                originX: 'left',
                originY: 'top'
            });
            c.add(t);
            c.setActiveObject(t);
            c.requestRenderAll();
            if (window.console) { console.log('[PD] Text adăugat (' + activeSide + '):', { left: t.left, top: t.top, fontSize: fontSize }); }
        } catch (err) {
            console.error('[PD] addText a eșuat:', err);
            say('Eroare la adăugarea textului. Verifică consola.');
        }
    }

    function deleteSelected() {
        var c = activeCanvas();
        if (!c) { return; }
        var obj = c.getActiveObject();
        if (!obj || obj.pdMockup) { return; }
        if (obj.type === 'activeSelection') {
            obj.forEachObject(function (o) {
                if (!o.pdMockup) { c.remove(o); }
            });
            c.discardActiveObject();
        } else {
            c.remove(obj);
        }
        c.requestRenderAll();
    }

    function uploadImage(file) {
        var c = activeCanvas();
        if (!c) { console.warn('[PD] uploadImage: canvas not ready yet.'); return; }
        if (!file) { return; }
        var maxBytes = PDData.limits.maxUploadMB * 1024 * 1024;
        if (file.size > maxBytes) { say(PDData.i18n.tooLarge); return; }
        if (PDData.limits.mimeTypes.indexOf(file.type) === -1) { say(PDData.i18n.badType); return; }

        var fd = new FormData();
        fd.append('file', file);

        say(PDData.i18n.uploading);
        $.ajax({
            url: PDData.rest.root + PDData.rest.uploadPath,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            headers: { 'X-WP-Nonce': PDData.rest.nonce }
        }).done(function (res) {
            if (!res || !res.url) {
                console.error('[PD] Upload a returnat răspuns invalid:', res);
                say('Răspuns invalid de la server.');
                return;
            }
            if (window.console) { console.log('[PD] Imagine uploadată:', res.url); }
            addUploadedImage(res.url);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || ('HTTP ' + xhr.status);
            console.error('[PD] Upload a eșuat:', msg, xhr);
            say('Eroare upload: ' + msg);
        });
    }

    function addUploadedImage(url) {
        var c = activeCanvas();
        if (!c) { return; }
        var native = new Image();
        if (!sameOrigin(url)) { native.crossOrigin = 'anonymous'; }

        native.onload = function () {
            try {
                var a = defaultSpawnArea();
                var maxW = a.width  * 0.8;
                var maxH = a.height * 0.8;
                var scale = Math.min(maxW / native.naturalWidth, maxH / native.naturalHeight, 1);
                var fImg = new fabric.Image(native, {
                    left: a.x + 10,
                    top:  a.y + 10,
                    originX: 'left', originY: 'top',
                    scaleX: scale, scaleY: scale
                });
                c.add(fImg);
                c.setActiveObject(fImg);
                c.requestRenderAll();
                say('');
            } catch (err) {
                console.error('[PD] addUploadedImage a eșuat:', err);
                say('Eroare la adăugarea imaginii. Verifică consola.');
            }
        };
        native.onerror = function () {
            console.error('[PD] Imaginea uploadată nu s-a putut încărca pe canvas:', url);
            say('Imaginea nu s-a putut afișa.');
        };
        native.src = url;
    }

    // ==========================================================================
    // Save (serializează ambele părți)
    // ==========================================================================

    function saveDesign() {
        var design = { version: 2, front: null, back: null };
        var preview = { front: '', back: '' };
        var totalObjects = 0;

        SIDES.forEach(function (side) {
            var c = canvases[side];
            if (!c) { return; }
            var sideJson = c.toJSON(['selectable', 'evented']);
            var userObjects = (sideJson.objects || []).filter(function (o) {
                return !o.excludeFromExport;
            });
            if (userObjects.length === 0) { return; }
            sideJson.objects = userObjects;

            var dataUri;
            try {
                dataUri = c.toDataURL({ format: 'png', multiplier: 1 });
            } catch (err) {
                console.error('[PD] toDataURL ' + side + ' a eșuat (canvas tainted?):', err);
                say('Eroare generare preview ' + side + '. Verifică consola.');
                throw err; // bubble out of forEach
            }

            design[side] = sideJson;
            preview[side] = dataUri;
            totalObjects += userObjects.length;
        });

        if (totalObjects === 0) {
            say(PDData.i18n.empty);
            return;
        }

        say(PDData.i18n.saving);
        $.ajax({
            url: PDData.rest.root + PDData.rest.designPath,
            method: 'POST',
            contentType: 'application/json',
            headers: { 'X-WP-Nonce': PDData.rest.nonce },
            data: JSON.stringify({
                product_id: PDData.product.id,
                design: design,
                preview: preview
            })
        }).done(function (res) {
            $hiddenId.val(res.design_id);
            $hiddenPng.val(res.preview_url || '');
            $hiddenPngBack.val(res.preview_back_url || '');
            $hiddenJson.val(res.json_url);
            // Preview thumbnail în UI: arată față dacă există, altfel spate.
            var thumb = res.preview_url || res.preview_back_url || '';
            if (thumb) {
                $selPrev.prop('hidden', false).find('img').attr('src', thumb);
            }
            say(PDData.i18n.savedOk);
            if (window.console) {
                console.log('[PD] Design salvat:', res.design_id,
                    '| front:', !!res.preview_url, '| back:', !!res.preview_back_url,
                    '| WC Session OK:', res.session_saved ? 'DA' : 'NU');
                if (!res.session_saved) {
                    console.warn('[PD] ATENȚIE: session NU s-a salvat pe server. Cart→order va rata designul dacă tema face AJAX add-to-cart.');
                }
            }
            setTimeout(closeModal, 500);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || ('HTTP ' + xhr.status);
            console.error('[PD] Salvarea a eșuat:', msg, xhr);
            say('Eroare salvare: ' + msg);
        });
    }

    // ==========================================================================
    // Event wiring
    // ==========================================================================

    $(document).on('click', '.pd-open-designer',           function (e) { e.preventDefault(); openModal(); });
    $(document).on('click', '.pd-modal__close, .pd-cancel',function (e) { e.preventDefault(); closeModal(); });
    $(document).on('click', '.pd-modal__backdrop',         function () { closeModal(); });

    $(document).on('click', '.pd-side-tab', function (e) {
        e.preventDefault();
        setActiveSide($(this).attr('data-side'));
    });

    $(document).on('click', '.pd-add-text', function (e) { e.preventDefault(); addText(); });
    $(document).on('click', '.pd-delete',   function (e) { e.preventDefault(); deleteSelected(); });
    $(document).on('click', '.pd-save',     function (e) { e.preventDefault(); saveDesign(); });

    $(document).on('change', '.pd-upload-input', function () {
        uploadImage(this.files && this.files[0]);
        this.value = '';
    });

    $(document).on('click', '.pd-clear-design', function (e) {
        e.preventDefault();
        $hiddenId.val(''); $hiddenPng.val(''); $hiddenPngBack.val(''); $hiddenJson.val('');
        $selPrev.prop('hidden', true).find('img').attr('src', '');
    });

    // --- Text controls wiring ---
    $(document).on('input change', '.pd-text-color', function () {
        var val = this.value;
        applyToActiveText(function (obj) { obj.set('fill', val); });
    });

    $(document).on('input change', '.pd-text-size', function () {
        var size = parseInt(this.value, 10);
        if (!size || size < 1) { return; }
        applyToActiveText(function (obj) { obj.set('fontSize', size); });
    });

    $(document).on('change', '.pd-text-font', function () {
        var val = this.value;
        applyToActiveText(function (obj) { obj.set('fontFamily', val); });
    });

    $(document).on('change', '.pd-text-align', function () {
        var val = this.value;
        applyToActiveText(function (obj) { obj.set('textAlign', val); });
    });

    $(document).on('click', '.pd-text-bold', function (e) {
        e.preventDefault();
        var $btn = $(this);
        applyToActiveText(function (obj) {
            var makeBold = obj.fontWeight !== 'bold';
            obj.set('fontWeight', makeBold ? 'bold' : 'normal');
            $btn.toggleClass('is-active', makeBold);
        });
    });

    $(document).on('click', '.pd-text-italic', function (e) {
        e.preventDefault();
        var $btn = $(this);
        applyToActiveText(function (obj) {
            var makeItalic = obj.fontStyle !== 'italic';
            obj.set('fontStyle', makeItalic ? 'italic' : 'normal');
            $btn.toggleClass('is-active', makeItalic);
        });
    });

    $(document).on('click', '.pd-text-underline', function (e) {
        e.preventDefault();
        var $btn = $(this);
        applyToActiveText(function (obj) {
            var makeU = !obj.underline;
            obj.set('underline', makeU);
            $btn.toggleClass('is-active', makeU);
        });
    });

    var resizeTimer;
    $(window).on('resize orientationchange', function () {
        if (!activeCanvas()) { return; }
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            fitCanvasToWrap();
        }, 120);
    });

    $(document).on('keydown', function (e) {
        if ($modal.length && $modal.prop('hidden')) { return; }
        var c = activeCanvas();
        var active = c && c.getActiveObject();
        if (e.key === 'Escape') {
            if (active && active.isEditing) { return; }
            closeModal();
        }
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (active && !active.isEditing && !active.pdMockup) {
                deleteSelected();
                e.preventDefault();
            }
        }
    });

    $(document).on('keydown', 'form.cart', function (e) {
        if (e.key === 'Enter' && !$modal.prop('hidden')) {
            e.preventDefault();
        }
    });

    $(document).on('submit', 'form.cart', function (e) {
        if (!$hiddenId || !$hiddenId.length || !PDData) { return; }
        if ($hiddenId.val() === '') {
            e.preventDefault();
            say(PDData.i18n.empty);
            openModal();
        }
    });

    // Hook pe AJAX add-to-cart: injectează design_id + ambele preview URL-uri.
    $(document.body).on('adding_to_cart', function (event, $button, data) {
        var id = $hiddenId && $hiddenId.length ? $hiddenId.val() : '';
        if (!id) { return; }
        data.pd_design_id        = id;
        data.pd_preview_url      = $hiddenPng.val();
        data.pd_preview_back_url = $hiddenPngBack.val();
        data.pd_json_url         = $hiddenJson.val();
        if (window.console) { console.log('[PD] Injectat design în adding_to_cart:', data.pd_design_id); }
    });

    // ==========================================================================
    // Public API — folosit de constructor.js
    // ==========================================================================

    function determineAvailableSides(data) {
        var sides = [];
        if (data && data.product) {
            if (data.product.mockup_front_url) { sides.push('front'); }
            if (data.product.mockup_back_url)  { sides.push('back'); }
        }
        // Fallback la legacy: dacă nici una din cele noi nu e setată dar avem mockup_url,
        // tratează ca single front. Dacă nimic, tot front (canvas gol cu fallback dim).
        if (sides.length === 0) {
            if (data && data.product && data.product.mockup_url) {
                data.product.mockup_front_url = data.product.mockup_url;
            }
            sides.push('front');
        }
        return sides;
    }

    function applySideTabsVisibility() {
        if ($sideTabs.length) {
            var bothSides = availableSides.length === 2;
            $sideTabs.prop('hidden', !bothSides);
            // Resetează count badges.
            $sideTabs.find('.pd-side-tab__count').text('0').prop('hidden', true);
            // Marchează tab-urile inactive ca disabled dacă acea parte lipsește.
            $sideTabs.find('.pd-side-tab').each(function () {
                var $t = $(this);
                var s = $t.attr('data-side');
                $t.prop('disabled', availableSides.indexOf(s) === -1);
                var isActive = (s === activeSide);
                $t.toggleClass('is-active', isActive).attr('aria-selected', isActive ? 'true' : 'false');
            });
        }
        // Ascunde COMPLET canvas-urile non-disponibile (înainte de Fabric init —
        // sigur să folosim hidden aici pentru că nu va exista canvas-container yet).
        // Pentru cele DISPONIBILE nu setăm hidden — `initCanvasForSide` va decide
        // vizibilitatea pe `.canvas-container` după ce Fabric wrap-uiește.
        SIDES.forEach(function (s) {
            var el = document.querySelector('.pd-canvas[data-side="' + s + '"]');
            if (!el) { return; }
            if (availableSides.indexOf(s) === -1) {
                el.hidden = true;
            } else {
                el.hidden = false;
                el.removeAttribute('hidden');
                // Dacă Fabric a init-uit deja, sincronizează containerul.
                if (canvases[s]) {
                    applyContainerVisibility(s);
                }
            }
        });
    }

    function disposeAllCanvases() {
        SIDES.forEach(function (s) {
            if (canvases[s]) {
                try { canvases[s].dispose(); } catch (e) { /* ignore */ }
                canvases[s] = null;
            }
        });
    }

    function boot(newData) {
        if (!newData) { return; }
        PDData = newData;
        cacheSelectors();

        disposeAllCanvases();

        // Reset state UI.
        if ($hiddenId.length)      { $hiddenId.val(''); }
        if ($hiddenPng.length)     { $hiddenPng.val(''); }
        if ($hiddenPngBack.length) { $hiddenPngBack.val(''); }
        if ($hiddenJson.length)    { $hiddenJson.val(''); }
        if ($selPrev.length)       { $selPrev.prop('hidden', true).find('img').attr('src', ''); }
        if ($textCtrls.length)     { $textCtrls.prop('hidden', true); }

        availableSides = determineAvailableSides(PDData);
        // Always default to front when available.
        activeSide = availableSides.indexOf('front') !== -1 ? 'front' : availableSides[0];
        applySideTabsVisibility();

        // Inițializează canvas-urile direct dacă modalul e vizibil sau dacă nu există modal
        // (caz constructor: designer-ul e inline).
        var modalHiddenAndExists = $modal.length && $modal.prop('hidden');
        if (!modalHiddenAndExists) {
            availableSides.forEach(function (s) { initCanvasForSide(s); });
            scheduleRefit();
            observeWrap();
        }
    }

    window.PDDesigner = { boot: boot };

    // Constructor.js poate emite acest event când user-ul alege produs nou.
    $(document).on('pd:mount', function (e) {
        var detail = e.originalEvent && e.originalEvent.detail;
        if (detail) { boot(detail); }
    });

    // Auto-boot single-product (PDData localized via wp_localize_script).
    if (PDData) {
        availableSides = determineAvailableSides(PDData);
        activeSide = availableSides.indexOf('front') !== -1 ? 'front' : availableSides[0];
        applySideTabsVisibility();
        // Nu inițializăm canvas — așteaptă click pe „Personalizează" (deschide modal).
    } else {
        if (window.console) { console.info('[PD] PDData not localized — așteaptă pd:mount din constructor.'); }
    }
})(jQuery);
