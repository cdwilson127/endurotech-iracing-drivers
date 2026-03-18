(function ($) {
    'use strict';

    $(document).on('click', '.edr-upload-btn', function (e) {
        e.preventDefault();
        var cid = $(this).data('cid');
        var frame = wp.media({
            title: 'Select Driver Photo',
            button: { text: 'Use This Photo' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var url = attachment.sizes && attachment.sizes.medium
                ? attachment.sizes.medium.url
                : attachment.url;

            $('#photo-' + cid).val(url);
            $('#preview-' + cid).html('<img src="' + url + '" alt="" />');

            var btn = $('[data-cid="' + cid + '"].edr-upload-btn');
            btn.text('Change Photo');

            if (!btn.siblings('.edr-remove-photo-btn').length) {
                btn.after(
                    '<button type="button" class="button edr-remove-photo-btn" data-cid="' + cid + '">Remove</button>'
                );
            }
        });

        frame.open();
    });

    $(document).on('click', '.edr-remove-photo-btn', function (e) {
        e.preventDefault();
        var cid = $(this).data('cid');
        $('#photo-' + cid).val('');
        $('#preview-' + cid).html('<span class="edr-no-photo">No photo</span>');
        $('[data-cid="' + cid + '"].edr-upload-btn').text('Upload Photo');
        $(this).remove();
    });
})(jQuery);
