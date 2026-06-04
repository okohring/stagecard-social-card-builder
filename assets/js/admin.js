(function ($) {
    'use strict';

    $(function () {
        const $url = $('.sccc-template-url');
        const $preview = $('.sccc-preview-img');
        const $shortcodeName = $('.sccc-shortcode-name');
        const $shortcodePreview = $('.sccc-shortcode-preview');
        let frame = null;

        function updatePreview(url) {
            if (!url) {
                $preview.attr('src', '').hide();
                return;
            }
            $preview.attr('src', url).show();
        }

        function normalizeShortcode(value) {
            value = String(value || '').trim().replace(/^\[/, '').replace(/\]$/, '');
            value = value.replace(/_social_card$/i, '');
            value = value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/_+/g, '_');
            return value || 'stagecard';
        }

        function updateShortcodePreview() {
            if (!$shortcodeName.length || !$shortcodePreview.length) {
                return;
            }
            const slug = normalizeShortcode($shortcodeName.val());
            $shortcodePreview.text('[' + slug + '_social_card]');
        }

        $('.sccc-template-upload').on('click', function (event) {
            event.preventDefault();

            if (typeof wp === 'undefined' || !wp.media) {
                alert('The WordPress Media Library could not be loaded. Refresh the page and try again.');
                return;
            }

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Choose social card graphic',
                button: {
                    text: 'Use this graphic'
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

        $url.on('input change', function () {
            updatePreview($(this).val());
        });

        $shortcodeName.on('input change', updateShortcodePreview);
        updateShortcodePreview();
    });
})(jQuery);
