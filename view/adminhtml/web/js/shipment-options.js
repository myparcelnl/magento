/**
 * The shipment options modal: loads the server-rendered form for orders or shipments, and posts
 * only the fields the merchant changed, so every other field keeps inheriting.
 *
 * The form marks what it needs with data-mypa-* attributes; data-initial holds the value it
 * rendered with, which is what "changed" is measured against. For one order, the refresh endpoint
 * fills the delivery list and re-renders the option block of the selection.
 */
define(
    [
        'jquery',
        'Magento_Ui/js/modal/modal',
        'mage/translate'
    ],
    function ($, modal) {
        'use strict';

        /** Wait this long after the last click before asking the server again. */
        var REFRESH_DELAY = 300;
        var STANDARD_TYPE = 'standard';
        var STANDARD = STANDARD_TYPE + '|';
        var PICKUP = 'pickup|';

        /** An admin POST with the form key; resolves with the decoded JSON answer. */
        function post(url, params) {
            var body = new URLSearchParams(params || {});

            // Admin POSTs are rejected without it.
            body.set('form_key', window.FORM_KEY);

            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            }).then(function (response) {
                return response.json();
            });
        }

        /** post(), with a network or parse failure turned into the answer shape {error}. */
        function postOrError(url, params) {
            return post(url, params).catch(function () {
                return {error: $.mage.__('The MyParcel options could not be loaded.')};
            });
        }

        function idParams(ids, idType) {
            var params = {};

            params[('shipment' === idType ? 'shipment_ids' : 'order_ids')] = ids.join(',');

            return params;
        }

        function isBulk($root) {
            return '1' === String($root.data('bulk'));
        }

        function selectedCarrier($root) {
            return $root.find('[data-mypa-carrier]:checked').val() || '';
        }

        function carrierBlock($root, carrier) {
            return $root.find('[data-mypa-carrier-block]').filter(function () {
                return $(this).attr('data-mypa-carrier-block') === carrier;
            });
        }

        function selectedPackageType($root) {
            return carrierBlock($root, selectedCarrier($root)).find('[data-mypa-package-type]:checked').val() || '';
        }

        function packageBlock($root) {
            var key = selectedCarrier($root) + '|' + selectedPackageType($root);

            return $root.find('[data-mypa-package-block]').filter(function () {
                return $(this).attr('data-mypa-package-block') === key;
            });
        }

        /** Shows the selected carrier and package type, picking the first of each when none is. */
        function toggle($root) {
            var $carriers = $root.find('[data-mypa-carrier]');
            var $types;

            if (!$carriers.filter(':checked').length) {
                $carriers.first().prop('checked', true);
            }

            $root.find('[data-mypa-carrier-block], [data-mypa-package-block]').hide();
            carrierBlock($root, selectedCarrier($root)).show();

            $types = carrierBlock($root, selectedCarrier($root)).find('[data-mypa-package-type]');

            if (!$types.filter(':checked').length) {
                $types.first().prop('checked', true);
            }

            packageBlock($root).show();
        }

        function deliveryRoot($root) {
            return $root.find('[data-mypa-delivery]');
        }

        function pickupRoot($root) {
            return $root.find('[data-mypa-pickup-list]');
        }

        /** The selected delivery list value, "<delivery type>|<Y-m-d or empty>", or '' before the list loads. */
        function selectedDelivery($root) {
            return deliveryRoot($root).find('select').val() || '';
        }

        function selectedLocation($root) {
            return pickupRoot($root).find('[data-mypa-pickup-location]:checked').val() || '';
        }

        function tickedOptions($root) {
            return packageBlock($root).find('[data-mypa-option]:checked').map(function () {
                return $(this).attr('data-mypa-option');
            }).get();
        }

        /** A date group label in the merchant's locale, for example "Thu 29 Oct". */
        function dateLabel(date) {
            return new Date(date + 'T00:00:00').toLocaleDateString(document.documentElement.lang || undefined, {
                weekday: 'short',
                day: 'numeric',
                month: 'short'
            });
        }

        function warning(text) {
            return $('<div class="message message-warning warning"></div>').append($('<div></div>').text(text));
        }

        /** @param {Object} list - DeliveryList::build(): groups, pickup, warning */
        function renderDeliveries($root, list) {
            var $select = $('<select class="admin__control-select" id="mypa_opt_delivery"></select>');
            var $container = deliveryRoot($root).empty();

            if (list.warning) {
                $container.append(warning(list.warning));
            }

            list.groups.forEach(function (group) {
                var $group;

                if (!group.entries.length) {
                    return;
                }

                $group = $('<optgroup></optgroup>').attr('label', group.date ? dateLabel(group.date) : group.label);

                group.entries.forEach(function (entry) {
                    $group.append($('<option></option>')
                        .val(entry.value)
                        .text(entry.label)
                        .attr('data-delivery-type', entry.deliveryType)
                        .attr('data-stored', entry.stored ? '1' : '0')
                        .prop('selected', entry.selected));
                });

                $select.append($group);
            });

            $container.append($select);
        }

        /**
         * Disables the entries capabilities do not allow with the ticked options. Standard and a stored
         * extra entry stay; a disabled selection falls back to standard without a date.
         */
        function filterDeliveries($root, allowed) {
            var $select = deliveryRoot($root).find('select');

            $select.find('option').each(function () {
                var $option = $(this);
                var type = $option.attr('data-delivery-type');
                var ok = '1' === $option.attr('data-stored') || STANDARD_TYPE === type || -1 !== allowed.indexOf(type);

                $option.prop('disabled', !ok).prop('hidden', !ok);
            });

            $select.find('optgroup').each(function () {
                $(this).prop('hidden', 0 === $(this).find('option:not(:disabled)').length);
            });

            if ($select.find('option:selected').prop('disabled')) {
                $select.val(STANDARD);
            }
        }

        function locationLabel(location) {
            var distance = Number(location.distance);
            var away = 1000 <= distance ? (distance / 1000).toFixed(1) + ' km' : distance + ' m';

            return location.location_name + ' — ' + location.street + ' ' + location.number + ', '
                + location.postal_code + ' ' + location.city + ' (' + away + ')';
        }

        /** Radios for the locations; the stored one is preselected, and kept when the API no longer lists it. */
        function renderLocations($root, locations) {
            var $list = pickupRoot($root).empty();
            var stored = String(pickupRoot($root).attr('data-initial') || '');
            var listed = locations.some(function (location) {
                return location.location_code === stored;
            });

            function radio(code, label) {
                var id = 'mypa_opt_pickup_' + code;

                return $('<div class="admin__field admin__field-option"></div>').append(
                    $('<input type="radio" class="admin__control-radio" name="mypa_opt_pickup_location" data-mypa-pickup-location>')
                        .attr('id', id).val(code).prop('checked', code === stored),
                    $('<label class="admin__field-label"></label>').attr('for', id).text(label)
                );
            }

            if (stored && !listed) {
                $list.append(radio(stored, $.mage.__('Current pickup location')));
            }

            locations.forEach(function (location) {
                $list.append(radio(location.location_code, locationLabel(location)));
            });
        }

        function differs($input) {
            var value = $input.is(':checkbox') ? ($input.prop('checked') ? '1' : '0') : String($input.val());

            return value !== String($input.attr('data-initial')) ? value : null;
        }

        /**
         * The changed fields as request parameters under changes[...]. In bulk an empty value is
         * Keep; on one order a value is changed when it differs from what the form rendered.
         */
        function changes($root) {
            var params = {};
            var carrier;
            var packageType;
            var $scope;

            function add($inputs, prefix) {
                $inputs.each(function () {
                    var $input = $(this);
                    var name = $input.attr('data-mypa-field') || $input.attr('data-mypa-option');
                    var value = differs($input);

                    // A field cleared on one order says so with data-mypa-empty; in bulk empty is Keep.
                    if ('' === value) {
                        value = $input.attr('data-mypa-empty') || '';
                    }

                    if (null !== value && '' !== value) {
                        params['changes' + prefix + '[' + name + ']'] = value;
                    }
                });
            }

            if (isBulk($root)) {
                add($root.find('[data-mypa-field]'), '');
                add($root.find('[data-mypa-option]'), '[options]');

                return params;
            }

            carrier = selectedCarrier($root);
            packageType = selectedPackageType($root);
            $scope = packageBlock($root);

            if (carrier && carrier !== String($root.data('carrier'))) {
                params['changes[carrier]'] = carrier;
            }

            if (packageType && (params['changes[carrier]'] || packageType !== String($root.data('package-type')))) {
                params['changes[package_type]'] = packageType;
            }

            add($scope.find('[data-mypa-field]'), '');
            // A greyed option is disabled but posts as off; a locked one is never posted.
            add($scope.find('[data-mypa-option]').filter(function () {
                return !this.disabled || 0 < $(this).closest('.mypa-option-greyed').length;
            }), '[options]');
            add($root.find('[data-mypa-order-field]'), '');
            addDelivery($root, params);

            return params;
        }

        /** The delivery fields, when the selection differs from what the order stores. */
        function addDelivery($root, params) {
            var delivery = selectedDelivery($root);
            var location = selectedLocation($root);
            var parts = delivery.split('|');
            var changed = delivery !== String(deliveryRoot($root).attr('data-initial') || '')
                || (PICKUP === delivery && location !== String(pickupRoot($root).attr('data-initial') || ''));

            if (!delivery || !changed) {
                return;
            }

            params['changes[delivery_type]'] = parts[0];

            if (PICKUP === delivery) {
                if (location) {
                    params['changes[pickup_location_code]'] = location;
                }

                return;
            }

            params['changes[delivery_date]'] = parts[1] || 'none';
        }

        /**
         * @param {Object}   config
         * @param {String}   config.formUrl
         * @param {String}   config.saveUrl
         * @param {String[]} config.ids
         * @param {String}   config.idType    - 'order' or 'shipment'
         * @param {String}   config.title
         * @param {String}   [config.extraHtml] - shown below the options, for the export settings
         * @param {Array}    config.buttons   - {text, class, click(api)}; the buttons stay disabled
         *                                     until the promise click returns settles
         * @param {Function} [config.onOpened] - called with the api once the form is in place
         *
         * @return {Object} the api the buttons receive
         */
        function open(config) {
            var $content = $('<div class="mypa-options-modal"></div>');
            var $messages = $('<div class="mypa-options-modal-messages"></div>');
            var $loading = $('<div class="mypa-options-loading"></div>').text($.mage.__('Loading...'));
            var $form = $('<div class="mypa-options-modal-form"></div>');
            // Hidden with the buttons until the form is in, so nothing below it jumps when it arrives.
            var $extra = $('<div class="mypa-options-modal-extra"></div>').append(config.extraHtml || '').hide();
            var $root = $();
            var $footer;
            var closed = false;
            var api;
            var state = {sequence: 0, timer: null, locations: {}};

            /** Asks the server what the selection allows; an answer older than the last request is dropped. */
            function refresh(withDates) {
                var sequence = ++state.sequence;
                var deliveryType = (selectedDelivery($root) || deliveryRoot($root).attr('data-initial') || STANDARD).split('|')[0];
                var params = $.extend(idParams(config.ids, config.idType), {
                    carrier: selectedCarrier($root),
                    package_type: selectedPackageType($root),
                    delivery_type: deliveryType,
                    with_dates: withDates ? '1' : '0'
                });

                clearTimeout(state.timer);

                tickedOptions($root).forEach(function (option, index) {
                    params['options[' + index + ']'] = option;
                });

                if (withDates) {
                    deliveryRoot($root).empty().append($('<div class="mypa-options-loading"></div>').text($.mage.__('Loading...')));
                }

                return postOrError(config.refreshUrl, params)
                    .then(function (answer) {
                        if (sequence !== state.sequence) {
                            return;
                        }

                        if (answer.error) {
                            deliveryRoot($root).find('.mypa-options-loading').remove();
                            api.error(answer.error);

                            return;
                        }

                        if (answer.optionsHtml) {
                            packageBlock($root).find('[data-mypa-options-block]').replaceWith(answer.optionsHtml);
                        }

                        if (answer.deliveries) {
                            renderDeliveries($root, answer.deliveries);
                        }

                        filterDeliveries($root, answer.allowedDeliveryTypes || []);
                        togglePickup();
                    });
            }

            function refreshSoon() {
                clearTimeout(state.timer);
                state.timer = setTimeout(function () {
                    refresh(false);
                }, REFRESH_DELAY);
            }

            /** Shows the pickup list while pickup is selected, loading it once per carrier. */
            function togglePickup() {
                var carrier = selectedCarrier($root);
                var $list = pickupRoot($root);

                if (PICKUP !== selectedDelivery($root)) {
                    $list.hide();

                    return;
                }

                $list.show();

                if (state.locations[carrier]) {
                    renderLocations($root, state.locations[carrier]);

                    return;
                }

                $list.empty().append($('<div class="mypa-options-loading"></div>').text($.mage.__('Loading...')));

                postOrError(config.pickupLocationsUrl, $.extend(idParams(config.ids, config.idType), {carrier: carrier}))
                    .then(function (answer) {
                        if (carrier !== selectedCarrier($root)) {
                            return;
                        }

                        if (answer.error) {
                            $list.empty().append(warning(answer.error));

                            return;
                        }

                        state.locations[carrier] = answer.locations || [];
                        renderLocations($root, state.locations[carrier]);
                    });
            }

            $content.append($loading, $messages, $form, $extra);

            api = {
                $content: $content,

                error: function (message) {
                    $messages.empty().append(
                        $('<div class="message message-error error"></div>').append($('<div></div>').text(message))
                    );
                },

                changes: function () {
                    return $root.length ? changes($root) : {};
                },

                hasChanges: function () {
                    return 0 < Object.keys(api.changes()).length;
                },

                /** Resolves with the answer once saved, or with {} when nothing changed. Rejects after showing why. */
                save: function () {
                    if (!api.hasChanges()) {
                        return Promise.resolve({});
                    }

                    $('body').trigger('processStart');

                    return post(config.saveUrl, $.extend(idParams(config.ids, config.idType), api.changes()))
                        .catch(function () {
                            return {error: $.mage.__('The MyParcel options could not be saved.')};
                        })
                        .then(function (answer) {
                            if (answer.error) {
                                api.error(answer.error);

                                throw new Error(answer.error);
                            }

                            return answer;
                        })
                        .finally(function () {
                            $('body').trigger('processStop');
                        });
                },

                close: function () {
                    closed = true;
                    $content.modal('closeModal');
                }
            };

            modal({
                type: 'popup',
                title: config.title,
                buttons: config.buttons.map(function (button) {
                    return {
                        text: button.text,
                        class: button.class || '',
                        click: function () {
                            var $buttons = $footer.find('button');

                            function enable() {
                                // A closing modal stays clickable while it fades out.
                                if (!closed) {
                                    $buttons.prop('disabled', false);
                                }
                            }

                            // Disabled, not only covered by the spinner: Enter on a focused button
                            // gets past it, and a second click can export the orders twice.
                            if ($buttons.prop('disabled')) {
                                return;
                            }

                            $buttons.prop('disabled', true);
                            Promise.resolve(button.click(api)).then(enable, enable);
                        }
                    };
                }),
                closed: function () {
                    $content.remove();
                }
            }, $content);

            $content.modal('openModal');

            $footer = $content.closest('.modal-inner-wrap').find('.modal-footer').hide();

            postOrError(config.formUrl, idParams(config.ids, config.idType))
                .then(function (answer) {
                    $loading.remove();

                    if (answer.error) {
                        // Nothing to save or export: the close button in the header stays.
                        api.error(answer.error);

                        return;
                    }

                    $form.html(answer.html);
                    $extra.show();
                    $footer.show();
                    $root = $form.find('[data-mypa-options]');
                    $root.on('change', '[data-mypa-carrier], [data-mypa-package-type]', function () {
                        toggle($root);

                        if (!isBulk($root)) {
                            refresh(true);
                        }
                    });

                    if (!isBulk($root)) {
                        $root.on('change', '[data-mypa-delivery] select', function () {
                            togglePickup();
                            refreshSoon();
                        });
                        $root.on('change', '[data-mypa-option]', function () {
                            var $box = $(this);
                            var $block = $box.closest('[data-mypa-options-block]');
                            var requires = JSON.parse($block.attr('data-mypa-requires') || '{}');

                            // An option also ticks what it requires, so capabilities get a valid combination.
                            if ($box.prop('checked')) {
                                (requires[$box.attr('data-mypa-option')] || []).forEach(function (option) {
                                    $block.find('[data-mypa-option="' + option + '"]').not(':disabled').prop('checked', true);
                                });
                            }

                            refreshSoon();
                        });

                        toggle($root);
                        refresh(true);
                    }

                    if (config.onOpened) {
                        config.onOpened(api);
                    }
                });

            return api;
        }

        return {open: open, post: post};
    }
);
