/* global jQuery, fabric, PDData */
/**
 * Product Designer — Fabric.js frontend editor.
 *
 * PDData is injected via wp_localize_script and contains:
 *   rest.{root,nonce,uploadPath,designPath}
 *   product.{id, mockup_url}
 *   canvas.{width,height}  (fallback când produsul n-are mockup)
 *   limits.{maxUploadMB, mimeTypes}
 *   i18n.{...}
 */
(function ($) {
    'use strict';

    if (typeof PDData === 'undefined') {
        if (window.console) { console.error('[PD] PDData missing — wp_localize_script did not run.'); }
        return;
    }
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

    // Cache-ăm canvas-ul ORIGINAL înainte ca fabric să-și adauge upper-canvas-ul.
    // După init, `$('.pd-canvas')` prinde AMBELE canvas-uri, ceea ce duce la bug-uri
    // subtile dacă re-selectăm.
    var canvasEl   = document.querySelector('.pd-canvas');
    var $modal     = $('.pd-modal');
    var $status    = $('.pd-status');
    var $deleteBtn = $('.pd-delete');
    var $hiddenId  = $('.pd-design-id');
    var $hiddenPng = $('.pd-preview-url');
    var $hiddenJson= $('.pd-json-url');
    var $selPrev   = $('.pd-selected-preview');

    // Controale text
    var $textCtrls     = $('.pd-text-controls');
    var $textColor     = $('.pd-text-color');
    var $textSize      = $('.pd-text-size');
    var $textFont      = $('.pd-text-font');
    var $textBold      = $('.pd-text-bold');
    var $textItalic    = $('.pd-text-italic');
    var $textUnderline = $('.pd-text-underline');
    var $textAlign     = $('.pd-text-align');

    var canvas = null;

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
        } else {
            fitCanvasToWrap(canvas.getWidth(), canvas.getHeight());
        }
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
            var w = native.naturalWidth, h = native.naturalHeight;
            if (!w || !h) {
                console.error('[PD] Mockup cu dimensiuni invalide:', url);
                say('Mockup invalid.');
                canvas.renderAll();
                return;
            }
            if (window.console) { console.log('[PD] Mockup încărcat:', url, w + 'x' + h); }

            // Canvas-ul primește exact dimensiunea nativă a mockup-ului —
            // coordonatele obiectelor salvate sunt direct în pixelii mockup-ului.
            canvas.setDimensions({ width: w, height: h });

            var fImg = new fabric.Image(native, {
                left: 0, top: 0,
                scaleX: 1, scaleY: 1,
                originX: 'left', originY: 'top',
                selectable: false,
                evented: false,
                hoverCursor: 'default',
                excludeFromExport: true
            });
            fImg.pdMockup = true;
            canvas.add(fImg);
            canvas.sendToBack(fImg);

            fitCanvasToWrap(w, h);
            canvas.renderAll();
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
        if (!wrap || !innerW || !innerH || !canvas) { return; }
        var padding = 32;
        var availW = Math.max(100, wrap.clientWidth  - padding);
        var availH = Math.max(100, wrap.clientHeight - padding);
        var scale  = Math.min(availW / innerW, availH / innerH, 1);
        canvas.setDimensions({
            width:  innerW * scale,
            height: innerH * scale
        }, { cssOnly: true });
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
    $(window).on('resize', function () {
        if (!canvas || $modal.prop('hidden')) { return; }
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            fitCanvasToWrap(canvas.getWidth(), canvas.getHeight());
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
        if (!$hiddenId.length) { return; }
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
        var id = $hiddenId.val();
        if (!id) { return; }
        data.pd_design_id   = id;
        data.pd_preview_url = $hiddenPng.val();
        data.pd_json_url    = $hiddenJson.val();
        if (window.console) { console.log('[PD] Injectat design în adding_to_cart:', data.pd_design_id); }
    });
})(jQuery);
