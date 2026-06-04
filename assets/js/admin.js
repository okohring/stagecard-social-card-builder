(function ($) {
    'use strict';

    $(function () {
        const $url = $('.stagecard-social-card-template-url');
        const $preview = $('.stagecard-social-card-template-preview');
        let frame = null;

        function updatePreview(url) {
            if (!url) {
                return;
            }
            $preview.attr('src', url);
        }

        $('.stagecard-social-card-template-upload').on('click', function (event) {
            event.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Choose social card template',
                button: {
                    text: 'Use this template'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            frame.on('select', function () {
                const attachment = frame.state().get('selection').first().toJSON();
                if (!attachment || !attachment.url) {
                    return;
                }
                $url.val(attachment.url).trigger('change');
                updatePreview(attachment.url);
            });

            frame.open();
        });

        $('.stagecard-social-card-template-reset').on('click', function (event) {
            event.preventDefault();
            const defaultUrl = $(this).attr('data-default-url') || '';
            $url.val(defaultUrl).trigger('change');
            updatePreview(defaultUrl);
        });

        $url.on('input change', function () {
            updatePreview($(this).val());
        });
    });
})(jQuery);
