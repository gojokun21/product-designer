/* global jQuery, wp, PDAdmin */
(function ($) {
    'use strict';

    // Legacy product-level fields: side → hidden input id used in the metabox markup.
    var LEGACY_INPUT_BY_SIDE = {
        front: '#pd_mockup_image_id',
        back:  '#pd_mockup_back_id'
    };

    /**
     * Resolve the hidden input + preview node a picker button operates on.
     *
     * Per-color rows wrap each picker in [data-pd-field]; the product-level
     * fields use fixed ids, so fall back to those for backwards compatibility.
     */
    function targetsFor($btn) {
        var $field = $btn.closest('[data-pd-field]');
        if ($field.length) {
            return {
                $hidden:  $field.find('input[type="hidden"]').first(),
                $preview: $field.find('.pd-mockup-preview').first(),
                $remove:  $field.find('.pd-remove-mockup').first()
            };
        }
        var side = $btn.attr('data-side') || 'front';
        var inputSel = LEGACY_INPUT_BY_SIDE[side];
        if (!inputSel) { return null; }
        return {
            $hidden:  $(inputSel),
            $preview: $('.pd-mockup-preview[data-side="' + side + '"]').first(),
            $remove:  $('.pd-remove-mockup[data-side="' + side + '"]').first()
        };
    }

    function openPicker($btn) {
        var t = targetsFor($btn);
        if (!t || !t.$hidden.length) { return; }

        var frame = wp.media({
            title:    PDAdmin.i18n.chooseMockup,
            button:   { text: PDAdmin.i18n.useMockup },
            library:  { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var maxW = $btn.closest('[data-pd-field]').length ? 90 : 220;
            t.$hidden.val(attachment.id);
            t.$preview.html('<img src="' + attachment.url + '" style="max-width:' + maxW + 'px;height:auto;" alt="" />');
            t.$remove.prop('disabled', false);
        });

        frame.open();
    }

    // ------------------------------------------------------------------
    // Printable-area drag editor (Product Designer tab → zonă imprimabilă).
    // Each .pd-pa-editor holds a mockup stage with a draggable/resizable box
    // and four hidden inputs storing the rectangle as fractions (0..1).
    // ------------------------------------------------------------------
    function clamp01(n) { return Math.max(0, Math.min(1, n)); }
    function round4(n) { return Math.round(n * 10000) / 10000; }
    var DEFAULT_AREA = { x: 0.25, y: 0.25, w: 0.5, h: 0.5 };
    var MIN_FRAC = 0.03;

    function paInputs($editor) {
        return {
            x: $editor.find('.pd-pa-x'),
            y: $editor.find('.pd-pa-y'),
            w: $editor.find('.pd-pa-w'),
            h: $editor.find('.pd-pa-h')
        };
    }

    function initPrintAreaEditor(editor) {
        var $editor = $(editor);
        var $stage  = $editor.find('.pd-pa-stage');
        var $box    = $editor.find('.pd-pa-box');
        var $enable = $editor.find('.pd-pa-enable');
        var inp     = paInputs($editor);

        function readFrac() {
            var x = parseFloat(inp.x.val()), y = parseFloat(inp.y.val()),
                w = parseFloat(inp.w.val()), h = parseFloat(inp.h.val());
            if (isNaN(x) || isNaN(y) || isNaN(w) || isNaN(h)) {
                return { x: DEFAULT_AREA.x, y: DEFAULT_AREA.y, w: DEFAULT_AREA.w, h: DEFAULT_AREA.h };
            }
            return { x: x, y: y, w: w, h: h };
        }
        function writeFrac(f) {
            inp.x.val(round4(f.x)); inp.y.val(round4(f.y));
            inp.w.val(round4(f.w)); inp.h.val(round4(f.h));
        }
        function layout(f) {
            $box.css({
                left:   (f.x * 100) + '%',
                top:    (f.y * 100) + '%',
                width:  (f.w * 100) + '%',
                height: (f.h * 100) + '%'
            });
        }
        function render() { layout(readFrac()); }

        $enable.on('change', function () {
            if ($(this).is(':checked')) {
                if (inp.x.val() === '') { writeFrac(DEFAULT_AREA); }
                $stage.show();
                render();
            } else {
                inp.x.val(''); inp.y.val(''); inp.w.val(''); inp.h.val('');
                $stage.hide();
            }
        });

        function startDrag(e, mode) {
            if ($stage.is(':hidden')) { return; }
            e.preventDefault();
            var rect = $stage[0].getBoundingClientRect();
            var sw = rect.width, sh = rect.height;
            if (!sw || !sh) { return; }
            var start = readFrac();
            var sx = e.clientX, sy = e.clientY;

            function onMove(ev) {
                var dx = (ev.clientX - sx) / sw;
                var dy = (ev.clientY - sy) / sh;
                var f;
                if (mode === 'move') {
                    f = {
                        x: Math.min(clamp01(start.x + dx), 1 - start.w),
                        y: Math.min(clamp01(start.y + dy), 1 - start.h),
                        w: start.w,
                        h: start.h
                    };
                } else {
                    var left = start.x, top = start.y,
                        right = start.x + start.w, bottom = start.y + start.h;
                    if (mode.indexOf('w') >= 0) { left  = Math.min(clamp01(start.x + dx), right - MIN_FRAC); }
                    if (mode.indexOf('e') >= 0) { right = Math.max(clamp01(right + dx), left + MIN_FRAC); }
                    if (mode.indexOf('n') >= 0) { top    = Math.min(clamp01(start.y + dy), bottom - MIN_FRAC); }
                    if (mode.indexOf('s') >= 0) { bottom = Math.max(clamp01(bottom + dy), top + MIN_FRAC); }
                    f = { x: left, y: top, w: right - left, h: bottom - top };
                }
                writeFrac(f); layout(f);
            }
            function onUp() { $(document).off('.pda'); }
            $(document).on('mousemove.pda', onMove).on('mouseup.pda', onUp);
        }

        $box.on('mousedown', function (e) {
            if ($(e.target).hasClass('pd-pa-handle')) { return; }
            startDrag(e, 'move');
        });
        $box.find('.pd-pa-handle').on('mousedown', function (e) {
            e.stopPropagation();
            startDrag(e, $(this).attr('data-h'));
        });

        render();
    }

    function applyGarmentPreset(type) {
        if (!type) { return; } // "Personalizat" leaves the current values as-is.
        var presets = (window.PDAdmin && PDAdmin.presets) || {};
        var preset = presets[type] || {};
        $('.pd-pa-editor').each(function () {
            var $editor = $(this);
            var side    = $editor.attr('data-side');
            var area    = preset[side] || null;
            var $enable = $editor.find('.pd-pa-enable');
            var $stage  = $editor.find('.pd-pa-stage');
            var inp     = paInputs($editor);
            if (area && !$enable.is(':disabled')) {
                $enable.prop('checked', true);
                inp.x.val(round4(area.x)); inp.y.val(round4(area.y));
                inp.w.val(round4(area.w)); inp.h.val(round4(area.h));
                $stage.show();
                $editor.find('.pd-pa-box').css({
                    left:   (area.x * 100) + '%',
                    top:    (area.y * 100) + '%',
                    width:  (area.w * 100) + '%',
                    height: (area.h * 100) + '%'
                });
            } else {
                $enable.prop('checked', false);
                inp.x.val(''); inp.y.val(''); inp.w.val(''); inp.h.val('');
                $stage.hide();
            }
        });
    }

    $(function () {
        $('.pd-pa-editor').each(function () { initPrintAreaEditor(this); });
        $(document).on('change', '.pd-garment-select', function () {
            applyGarmentPreset($(this).val());
        });

        $(document).on('click', '.pd-upload-mockup', function (e) {
            e.preventDefault();
            openPicker($(this));
        });

        $(document).on('click', '.pd-remove-mockup', function (e) {
            e.preventDefault();
            var t = targetsFor($(this));
            if (!t || !t.$hidden.length) { return; }
            t.$hidden.val('');
            t.$preview.empty();
            $(this).prop('disabled', true);
        });

        // Per-color section: switching the driving attribute shows its group only.
        $(document).on('change', '.pd-color-attr-select', function () {
            var attr = $(this).val();
            $('.pd-color-mockups').each(function () {
                $(this).prop('hidden', $(this).attr('data-attr') !== attr);
            });
        });
    });
})(jQuery);
