/* global jQuery, wp, PDAdmin */
(function ($) {
    'use strict';

    // Map side → hidden input id used in the metabox markup.
    var INPUT_BY_SIDE = {
        front: '#pd_mockup_image_id',
        back:  '#pd_mockup_back_id'
    };

    var frames = {};

    function selectMockup(side) {
        var inputSel = INPUT_BY_SIDE[side];
        if (!inputSel) { return; }
        var $hidden  = $(inputSel);
        var $preview = $('.pd-mockup-preview[data-side="' + side + '"]').first();

        if (frames[side]) { frames[side].open(); return; }

        frames[side] = wp.media({
            title:    PDAdmin.i18n.chooseMockup,
            button:   { text: PDAdmin.i18n.useMockup },
            library:  { type: 'image' },
            multiple: false
        });

        frames[side].on('select', function () {
            var attachment = frames[side].state().get('selection').first().toJSON();
            $hidden.val(attachment.id);
            $preview.html('<img src="' + attachment.url + '" style="max-width:220px;height:auto;" alt="" />');
            $('.pd-remove-mockup[data-side="' + side + '"]').prop('disabled', false);
        });

        frames[side].open();
    }

    $(function () {
        $(document).on('click', '.pd-upload-mockup', function (e) {
            e.preventDefault();
            // Default to "front" for backwards compatibility with any markup that
            // still omits data-side.
            selectMockup($(this).attr('data-side') || 'front');
        });

        $(document).on('click', '.pd-remove-mockup', function (e) {
            e.preventDefault();
            var side = $(this).attr('data-side') || 'front';
            var inputSel = INPUT_BY_SIDE[side];
            if (!inputSel) { return; }
            $(inputSel).val('');
            $('.pd-mockup-preview[data-side="' + side + '"]').empty();
            $(this).prop('disabled', true);
        });
    });
})(jQuery);
