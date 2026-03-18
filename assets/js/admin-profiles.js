/* Endurotech iRacing Drivers — Admin Profiles JS v1.6 */
(function ($) {
    'use strict';

    /* ----------------------------------------------------------------
     * Media uploader — driver photos
     * Prefers the edr-driver-photo (400×500) size if available.
     * ---------------------------------------------------------------- */
    $(document).on('click', '.edr-upload-btn', function (e) {
        e.preventDefault();
        var cid  = $(this).data('cid');
        var $btn = $(this);

        var frame = wp.media({
            title:    'Select Driver Photo',
            button:   { text: 'Use This Photo' },
            multiple: false,
            library:  { type: 'image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var url = '';

            // Prefer the custom portrait crop, fall back gracefully
            if (attachment.sizes) {
                url = (attachment.sizes['edr-driver-photo'] && attachment.sizes['edr-driver-photo'].url)
                    || (attachment.sizes['medium_large'] && attachment.sizes['medium_large'].url)
                    || (attachment.sizes['medium'] && attachment.sizes['medium'].url)
                    || attachment.url;
            } else {
                url = attachment.url;
            }

            $('#photo-' + cid).val(url);
            $('#preview-' + cid).html('<img src="' + url + '" alt="" />');
            $btn.text('Change Photo');

            if ( ! $btn.siblings('.edr-remove-photo-btn').length ) {
                $btn.after(
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

    /* ----------------------------------------------------------------
     * Country / flag dropdown — auto-fill nationality text field
     * ---------------------------------------------------------------- */
    var countryNames = {
        AU:'Australia', NZ:'New Zealand', GB:'United Kingdom', IE:'Ireland',
        US:'United States', CA:'Canada', MX:'Mexico', BR:'Brazil', AR:'Argentina',
        CL:'Chile', CO:'Colombia', DE:'Germany', FR:'France', IT:'Italy',
        ES:'Spain', PT:'Portugal', NL:'Netherlands', BE:'Belgium', SE:'Sweden',
        NO:'Norway', DK:'Denmark', FI:'Finland', AT:'Austria', CH:'Switzerland',
        PL:'Poland', CZ:'Czech Republic', HU:'Hungary', SK:'Slovakia', RO:'Romania',
        HR:'Croatia', RS:'Serbia', SI:'Slovenia', GR:'Greece', RU:'Russia',
        UA:'Ukraine', EE:'Estonia', LV:'Latvia', LT:'Lithuania', IS:'Iceland',
        TR:'Turkey', IL:'Israel', AE:'UAE', ZA:'South Africa', JP:'Japan',
        KR:'South Korea', CN:'China', IN:'India', SG:'Singapore', TH:'Thailand'
    };

    $(document).on('change', '.edr-flag-select', function () {
        var cid  = $(this).data('cid');
        var code = $(this).val();
        var $nat = $('#nat-' + cid);

        // Only auto-fill if the nationality field is blank
        if ( $nat.length && $nat.val().trim() === '' && code && countryNames[code] ) {
            $nat.val(countryNames[code]);
        }
    });

    /* ----------------------------------------------------------------
     * Fetch Stats per driver — AJAX call with diagnostic output
     * ---------------------------------------------------------------- */
    $(document).on('click', '.edr-fetch-driver-btn', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var custId    = $btn.data('cid');
        var driverId  = $btn.data('driver-id');
        var $result   = $('#fetch-result-' + driverId);

        $btn.prop('disabled', true).text('Fetching...');
        $result.html('<p style="color:#888">Calling iRacing API...</p>').show();

        $.post(edrAdmin.ajaxUrl, {
            action:  'edr_fetch_single_driver',
            nonce:   edrAdmin.nonce,
            cust_id: custId
        }, function (resp) {
            $btn.prop('disabled', false).text('Fetch Stats from iRacing');

            if ( ! resp.success ) {
                $result.html('<div class="notice notice-error inline"><p>' + (resp.data || 'Unknown error') + '</p></div>');
                return;
            }

            var d = resp.data;
            var html = '<div style="background:#f9f9f9;border:1px solid #ddd;padding:12px 16px;margin:8px 0 12px;font-size:12px;font-family:monospace;max-height:400px;overflow:auto">';

            // Sports Car iRating + SR
            html += '<strong>Sports Car iRating:</strong> ' + (d.sports_car_irating !== null ? '<span style="color:#0a7227;font-weight:bold">' + d.sports_car_irating + '</span>' : '<span style="color:#b32d2e">NOT FOUND</span>') + '<br>';
            html += '<strong>Sports Car Safety Rating:</strong> ' + (d.sports_car_safety_rating !== null ? d.sports_car_safety_rating : 'NOT FOUND') + '<br>';

            if (d.warning) {
                html += '<br><span style="color:#b32d2e;font-weight:bold">' + d.warning + '</span><br>';
            }

            // All licenses
            if (d.all_licenses && d.all_licenses.length) {
                html += '<br><strong>All licenses returned by /member/get:</strong><br>';
                html += '<table style="border-collapse:collapse;font-size:11px;margin:4px 0 8px"><tr style="background:#eee"><th style="padding:3px 8px;border:1px solid #ccc">Cat ID</th><th style="padding:3px 8px;border:1px solid #ccc">Category</th><th style="padding:3px 8px;border:1px solid #ccc">iRating</th><th style="padding:3px 8px;border:1px solid #ccc">SR</th></tr>';
                for (var i = 0; i < d.all_licenses.length; i++) {
                    var lic = d.all_licenses[i];
                    var isSC = (lic.category_id === 5) ? ' style="background:#ffe;font-weight:bold"' : '';
                    html += '<tr' + isSC + '><td style="padding:3px 8px;border:1px solid #ccc">' + lic.category_id + '</td><td style="padding:3px 8px;border:1px solid #ccc">' + lic.category_name + '</td><td style="padding:3px 8px;border:1px solid #ccc">' + lic.irating + '</td><td style="padding:3px 8px;border:1px solid #ccc">' + lic.safety_rating + '</td></tr>';
                }
                html += '</table>';
            } else {
                html += '<br><span style="color:#b32d2e">No licenses array returned by /member/get — the endpoint may not support this field.</span><br>';
            }

            // Recent races
            html += '<strong>Recent races returned:</strong> ' + d.recent_races_count + '<br>';
            if (d.recent_races_first5 && d.recent_races_first5.length) {
                html += '<strong>First 5 (newest first):</strong><br>';
                for (var j = 0; j < d.recent_races_first5.length; j++) {
                    var r = d.recent_races_first5[j];
                    html += '&nbsp;&nbsp;cat_id=' + (r.category_id || '?') + ' | newi_rating=' + (r.newi_rating !== undefined ? r.newi_rating : '?') + ' | series=' + (r.series_name || '?') + '<br>';
                }
            }

            html += '</div>';

            // Auto-fill placeholders with fetched SC values if fields are empty
            if (d.sports_car_irating !== null) {
                var $ir = $('#stat-' + driverId + '-irating');
                if ($ir.length && $ir.val() === '') { $ir.attr('placeholder', 'API: ' + d.sports_car_irating); }
            }
            if (d.sports_car_safety_rating !== null) {
                var srDisplay = d.sports_car_safety_rating;
                if (typeof srDisplay === 'number' && srDisplay > 10) { srDisplay = (srDisplay / 100).toFixed(2); }
                var $sr = $('#stat-' + driverId + '-safety_rating');
                if ($sr.length && $sr.val() === '') { $sr.attr('placeholder', 'API: ' + srDisplay); }
            }

            $result.html(html);
        }).fail(function () {
            $btn.prop('disabled', false).text('Fetch Stats from iRacing');
            $result.html('<div class="notice notice-error inline"><p>AJAX request failed. Check your connection.</p></div>');
        });
    });

    /* ----------------------------------------------------------------
     * Drag-to-reorder driver cards (HTML5 drag & drop)
     * Updates the Display Order (sort_order) inputs on drop.
     * ---------------------------------------------------------------- */
    var $grid = $('#edr-sortable-grid');
    if ( ! $grid.length ) { return; }

    var dragSrc = null;

    function getDraggableCards() {
        return $grid.find('.edr-profile-card').get();
    }

    function updateSortOrders() {
        getDraggableCards().forEach(function (card, index) {
            $(card).find('.edr-sort-order-input').val(index + 1);
        });
    }

    function addDragHandlers(card) {
        card.setAttribute('draggable', 'true');

        card.addEventListener('dragstart', function (e) {
            dragSrc = card;
            card.classList.add('edr-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', card.outerHTML);
        });

        card.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            card.classList.add('edr-drag-over');
        });

        card.addEventListener('dragleave', function () {
            card.classList.remove('edr-drag-over');
        });

        card.addEventListener('drop', function (e) {
            e.preventDefault();
            card.classList.remove('edr-drag-over');

            if ( dragSrc && dragSrc !== card ) {
                var $grid   = $('#edr-sortable-grid');
                var $src    = $(dragSrc);
                var $target = $(card);
                var $cards  = $grid.children('.edr-profile-card');
                var srcIdx  = $cards.index($src);
                var tgtIdx  = $cards.index($target);

                if ( srcIdx < tgtIdx ) {
                    $src.insertAfter($target);
                } else {
                    $src.insertBefore($target);
                }

                updateSortOrders();
            }
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('edr-dragging');
            getDraggableCards().forEach(function (c) {
                c.classList.remove('edr-drag-over');
            });
        });
    }

    // Initialise existing cards
    getDraggableCards().forEach(addDragHandlers);

})(jQuery);
