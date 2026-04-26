/* global jQuery, fabric */
/**
 * Product Designer — Fabric.js frontend editor.
 *
 * PDData shape:
 *   rest.{root,nonce,uploadPath,designPath}
 *   product.{id, mockup_url}
 *   canvas.{width,height}  (fallback când produsul n-are mockup)
 *   limits.{maxUploadMB, mimeTypes}
 *   i18n.{...}
 *
 * PDData provine din:
 *   - `wp_localize_script` pe single-product (auto-boot)
 *   - constructor.js care emite event `pd:mount` cu detail = PDData
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
    // PNG la mockup-uri uriașe (4K+). Coordonatele rămân raportate la canvas, deci
    // un design salvat pe un mockup capat e identic cu unul salvat pe nativ.
    var MAX_INTERNAL_DIM = 2000;

    // Selectorii sunt re-cache-uiți la fiecare boot, ca să prindă DOM-ul curent
    // (constructorul poate înlocui markup-ul când user-ul schimbă produsul).
    var canvasEl, $modal, $status, $deleteBtn, $hiddenId, $hiddenPng, $hiddenJson, $selPrev;
    var $textCtrls, $textColor, $textSize, $textFont, $textBold, $textItalic, $textUnderline, $textAlign;
    var canvas = null;

    function cacheSelectors() {
        canvasEl       = document.querySelector('.pd-canvas');
        $modal         = $('.pd-modal');
        $status        = $('.pd-status');
        $deleteBtn     = $('.pd-delete');
        $hiddenId      = $('.pd-design-id');
        $hiddenPng     = $('.pd-preview-url');
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
    }
    cacheSelectors();

    function say(msg) { $status.text(msg || ''); }

    function sameOrigin(url) {
        try { return new URL(url, window.location.href).origin === window.location.origin; }
        catch (e) { return true; }
    }

    function openModal() {
        $modal.prop('hidden', false).attr('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (!canvas) {
            initCanvas();
        }
        // Așteaptă două frame-uri ca layout-ul modalului să se așeze înainte
        // să măsurăm wrap-ul. Fără asta, prima măsurătoare prinde wrap.clientWidth=0
        // (modalul tocmai a devenit vizibil) → scale=1 → canvas la dim. nativă → overflow.
        scheduleRefit();
        observeWrap();
    }
    function closeModal() {
        $modal.prop('hidden', true).attr('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function initCanvas() {
        if (!canvasEl) {
            console.error('[PD] .pd-canvas element not found in DOM.');
            return;
        }
        canvas = new fabric.Canvas(canvasEl, {
            preserveObjectStacking: true,
            backgroundColor: '#fff',
            // Fabric 5.x are un bug cu `cssOnly: true` + retina scaling:
            // când canvas-ul e micșorat doar via CSS pe ecrane HiDPI
            // (Windows 125%, retina Mac), coordonatele mouse-ului se
            // calculează greșit cu factor DPR. Dezactivăm retina scaling
            // ca să garantăm că click-urile pe text/imagini nimeresc corect.
            enableRetinaScaling: false
        });

        canvas.setDimensions({
            width:  PDData.canvas.width,
            height: PDData.canvas.height
        });

        canvas.on('selection:created', onSelection);
        canvas.on('selection:updated', onSelection);
        canvas.on('selection:cleared', function () {
            $deleteBtn.prop('disabled', true);
            $textCtrls.prop('hidden', true);
        });

        if (PDData.product.mockup_url) {
            loadMockup(PDData.product.mockup_url);
        } else {
            canvas.renderAll();
        }
    }

    function loadMockup(url) {
        var native = new Image();
        if (!sameOrigin(url)) { native.crossOrigin = 'anonymous'; }

        native.onload = function () {
            var nw = native.naturalWidth, nh = native.naturalHeight;
            if (!nw || !nh) {
                console.error('[PD] Mockup cu dimensiuni invalide:', url);
                say('Mockup invalid.');
                canvas.renderAll();
                return;
            }

            // Cap rezoluția internă: dacă mockup-ul depășește MAX_INTERNAL_DIM
            // pe latura mare, micșorăm canvas-ul + scalăm imaginea în consecință.
            var cap = Math.min(MAX_INTERNAL_DIM / nw, MAX_INTERNAL_DIM / nh, 1);
            var w = Math.round(nw * cap);
            var h = Math.round(nh * cap);

            if (window.console) {
                console.log('[PD] Mockup încărcat:', url,
                    'native ' + nw + 'x' + nh +
                    (cap < 1 ? ' → canvas redus la ' + w + 'x' + h + ' (cap ' + MAX_INTERNAL_DIM + 'px)' : ''));
            }

            canvas.setDimensions({ width: w, height: h });

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
            canvas.add(fImg);
            canvas.sendToBack(fImg);

            // Apelul sincron prinde uneori wrap-ul cu dimensiuni stale (browser-ul
            // n-a re-layout-uit încă pentru noul canvas-container). Schedule un
            // re-fit în 2 frame-uri pentru măsurătoare fiabilă. Apoi observă
            // wrap-ul ca să prindem schimbări ulterioare (modal resize, etc).
            fitCanvasToWrap(w, h);
            canvas.renderAll();
            scheduleRefit();
            observeWrap();
        };
        native.onerror = function () {
            console.error('[PD] Eșec la încărcarea mockup-ului:', url);
            say('Mockup nu s-a putut încărca. Verifică consola.');
            canvas.renderAll();
        };
        native.src = url;
    }

    function fitCanvasToWrap(innerW, innerH) {
        var wrap = $('.pd-canvas-wrap')[0];
        if (!wrap || !canvas) { return; }
        // Argumentele sunt opționale — fallback la dimensiunea internă curentă
        // a canvas-ului. Util pentru re-fit la resize.
        innerW = innerW || canvas.getWidth();
        innerH = innerH || canvas.getHeight();
        if (!innerW || !innerH) { return; }
        var padding = 32;
        var availW = Math.max(100, wrap.clientWidth  - padding);
        var availH = Math.max(100, wrap.clientHeight - padding);
        var scale  = Math.min(availW / innerW, availH / innerH, 1);
        canvas.setDimensions({
            width:  Math.round(innerW * scale),
            height: Math.round(innerH * scale)
        }, { cssOnly: true });
    }

    // Re-fit programat în următoarele 2 animation frame-uri. Necesar după
    // openModal / boot — la momentul apelului, browser-ul încă n-a calculat
    // layout-ul pentru elementele tocmai devenite vizibile.
    function scheduleRefit() {
        if (!canvas) { return; }
        var raf = window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); };
        raf(function () {
            raf(function () {
                fitCanvasToWrap();
            });
        });
    }

    // ResizeObserver pe .pd-canvas-wrap. Se declanșează nu doar la window resize,
    // ci și când sidebar-ul își schimbă lățimea, modalul își schimbă mărimea, etc.
    var wrapObserver = null;
    var wrapObserverTimer = null;
    function observeWrap() {
        if (typeof window.ResizeObserver === 'undefined') { return; }
        var wrap = document.querySelector('.pd-canvas-wrap');
        if (!wrap) { return; }
        // Dispose observer vechi (poate fi pe alt nod DOM după boot()).
        if (wrapObserver) {
            try { wrapObserver.disconnect(); } catch (e) { /* ignore */ }
        }
        wrapObserver = new ResizeObserver(function () {
            if (!canvas) { return; }
            clearTimeout(wrapObserverTimer);
            wrapObserverTimer = setTimeout(function () {
                fitCanvasToWrap();
            }, 80);
        });
        wrapObserver.observe(wrap);
    }

    function onSelection() {
        var obj = canvas.getActiveObject();
        $deleteBtn.prop('disabled', !obj);
        syncTextControls(obj);
    }

    function isTextObject(obj) {
        return !!obj && (obj.type === 'i-text' || obj.type === 'text' || obj.type === 'textbox');
    }

    // Populează controalele cu valorile obiectului selectat (sau ascunde-le).
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

    // Input type="color" acceptă doar #rrggbb. Convertim din orice valoare
    // internă fabric (ex. 'rgb(17,17,17)' sau nume CSS) la hex.
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
        if (!canvas) { return; }
        var obj = canvas.getActiveObject();
        if (!isTextObject(obj)) { return; }
        mutator(obj);
        obj.setCoords();
        canvas.requestRenderAll();
    }

    // Centrul canvas-ului — zonă implicită pentru poziționarea obiectelor noi.
    function defaultSpawnArea() {
        var canvasW = canvas ? canvas.getWidth()  : 600;
        var canvasH = canvas ? canvas.getHeight() : 700;
        var w = Math.round(canvasW * 0.6);
        var h = Math.round(canvasH * 0.6);
        return { x: Math.round((canvasW - w) / 2), y: Math.round((canvasH - h) / 2), width: w, height: h };
    }

    // --- Tools ---

    function addText() {
        if (!canvas) {
            console.warn('[PD] addText: canvas not ready yet.');
            return;
        }
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
            canvas.add(t);
            canvas.setActiveObject(t);
            canvas.requestRenderAll();
            if (window.console) { console.log('[PD] Text adăugat:', { left: t.left, top: t.top, fontSize: fontSize, fill: t.fill }); }
        } catch (err) {
            console.error('[PD] addText a eșuat:', err);
            say('Eroare la adăugarea textului. Verifică consola.');
        }
    }

    function deleteSelected() {
        if (!canvas) { return; }
        var obj = canvas.getActiveObject();
        if (!obj || obj.pdMockup) { return; }
        if (obj.type === 'activeSelection') {
            obj.forEachObject(function (o) {
                if (!o.pdMockup) { canvas.remove(o); }
            });
            canvas.discardActiveObject();
        } else {
            canvas.remove(obj);
        }
        canvas.requestRenderAll();
    }

    function uploadImage(file) {
        if (!canvas) {
            console.warn('[PD] uploadImage: canvas not ready yet.');
            return;
        }
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
                canvas.add(fImg);
                canvas.setActiveObject(fImg);
                canvas.requestRenderAll();
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

    function saveDesign() {
        if (!canvas) { return; }

        var design = canvas.toJSON(['selectable', 'evented']);
        var userObjects = (design.objects || []).filter(function (o) {
            return !o.excludeFromExport;
        });
        if (userObjects.length === 0) {
            say(PDData.i18n.empty);
            return;
        }
        design.objects = userObjects;

        var preview;
        try {
            preview = canvas.toDataURL({ format: 'png', multiplier: 1 });
        } catch (err) {
            console.error('[PD] toDataURL a eșuat (canvas tainted?):', err);
            say('Eroare generare preview. Verifică consola.');
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
            $hiddenPng.val(res.preview_url);
            $hiddenJson.val(res.json_url);
            $selPrev.prop('hidden', false).find('img').attr('src', res.preview_url);
            say(PDData.i18n.savedOk);
            if (window.console) {
                console.log('[PD] Design salvat:', res.design_id,
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

    // --- Event wiring ---
    $(document).on('click', '.pd-open-designer',           function (e) { e.preventDefault(); openModal(); });
    $(document).on('click', '.pd-modal__close, .pd-cancel',function (e) { e.preventDefault(); closeModal(); });
    $(document).on('click', '.pd-modal__backdrop',         function () { closeModal(); });

    $(document).on('click', '.pd-add-text', function (e) { e.preventDefault(); addText(); });
    $(document).on('click', '.pd-delete',   function (e) { e.preventDefault(); deleteSelected(); });
    $(document).on('click', '.pd-save',     function (e) { e.preventDefault(); saveDesign(); });

    $(document).on('change', '.pd-upload-input', function () {
        uploadImage(this.files && this.files[0]);
        this.value = '';
    });

    $(document).on('click', '.pd-clear-design', function (e) {
        e.preventDefault();
        $hiddenId.val(''); $hiddenPng.val(''); $hiddenJson.val('');
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
        if (!canvas) { return; }
        // În constructor mode designer-ul e inline (modalul nu există / e gol),
        // deci nu mai filtrăm pe $modal.hidden — fitCanvasToWrap e safe oricum.
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            fitCanvasToWrap();
        }, 120);
    });

    $(document).on('keydown', function (e) {
        if ($modal.prop('hidden')) { return; }
        var active = canvas && canvas.getActiveObject();
        if (e.key === 'Escape') {
            // Dacă user-ul editează un IText, Escape iese din edit, nu închide modalul.
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

    // Previne submit-ul formularului add-to-cart dacă user-ul apasă Enter
    // în timp ce editează un IText sau altceva în modal.
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

    // Hook pe evenimentul WC AJAX add-to-cart: injectează design_id în payload
    // ca să ajungă în $_POST la backend indiferent că tema folosește AJAX.
    // Triggeruit de `woocommerce.js` standard + majoritatea temelor WC.
    $(document.body).on('adding_to_cart', function (event, $button, data) {
        var id = $hiddenId && $hiddenId.length ? $hiddenId.val() : '';
        if (!id) { return; }
        data.pd_design_id   = id;
        data.pd_preview_url = $hiddenPng.val();
        data.pd_json_url    = $hiddenJson.val();
        if (window.console) { console.log('[PD] Injectat design în adding_to_cart:', data.pd_design_id); }
    });

    // ==========================================================================
    // Public API — folosit de constructor.js
    // ==========================================================================
    function boot(newData) {
        if (!newData) { return; }
        PDData = newData;
        // Re-cache selectors (DOM-ul poate fi schimbat de constructor între mounts).
        cacheSelectors();

        // Dispose canvas anterior dacă există (schimbare produs în constructor).
        if (canvas) {
            try { canvas.dispose(); } catch (e) { /* ignore */ }
            canvas = null;
        }

        // Reset state UI.
        if ($hiddenId.length)  { $hiddenId.val(''); }
        if ($hiddenPng.length) { $hiddenPng.val(''); }
        if ($hiddenJson.length){ $hiddenJson.val(''); }
        if ($selPrev.length)   { $selPrev.prop('hidden', true).find('img').attr('src', ''); }
        if ($textCtrls.length) { $textCtrls.prop('hidden', true); }

        // Inițializează canvas-ul direct dacă modalul e vizibil (caz constructor:
        // designer-ul e inline, deja vizibil). Pe single-product așteaptă click.
        if (canvasEl && (!$modal.length || !$modal.prop('hidden'))) {
            initCanvas();
            // Wrap-ul tocmai a fost (re)montat de constructor — observăm noul nod
            // și schedule un refit după ce layout-ul se așează.
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
        // Selectorii sunt deja cache-uiți; nu auto-init canvas — așteaptă click pe „Personalizează".
    } else {
        if (window.console) { console.info('[PD] PDData not localized — așteaptă pd:mount din constructor.'); }
    }
})(jQuery);
