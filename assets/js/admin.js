/* global jQuery, wp, PDAdmin */
(function ($) {
    'use strict';

    $(function () {
        var $hidden  = $('#pd_mockup_image_id');
        var $preview = $('.pd-mockup-preview');
        var frame;

        $(document).on('click', '.pd-upload-mockup', function (e) {
            e.preventDefault();

            if (frame) { frame.open(); return; }

            frame = wp.media({
                title: PDAdmin.i18n.chooseMockup,
                button: { text: PDAdmin.i18n.useMockup },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $hidden.val(attachment.id);
                $preview.html('<img src="' + attachment.url + '" style="max-width:220px;height:auto;" alt="" />');
                $('.pd-remove-mockup').prop('disabled', false);
            });

            frame.open();
        });

        $(document).on('click', '.pd-remove-mockup', function (e) {
            e.preventDefault();
            $hidden.val('');
            $preview.empty();
            $(this).prop('disabled', true);
        });
    });
})(jQuery);
