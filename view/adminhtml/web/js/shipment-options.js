/**
 * The shipment options modal: loads the server-rendered form for orders or shipments, and posts
 * only the fields the merchant changed, so every other field keeps inheriting.
 *
 * The form marks what it needs with data-mypa-* attributes; data-initial holds the value it
 * rendered with, which is what "changed" is measured against.
 */
define(
    [
        'jquery',
        'Magento_Ui/js/modal/modal',
        'mage/translate'
    ],
    function ($, modal) {
        'use strict';

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

            $root.find('[data-mypa-pickup-warning]').toggle(
                '1' === String($root.data('pickup')) && selectedCarrier($root) !== String($root.data('carrier'))
            );
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

                if ('remove' === $root.find('[data-mypa-bulk-date]').val()) {
                    params['changes[delivery_date]'] = 'none';
                } else if ('set' === $root.find('[data-mypa-bulk-date]').val() && $root.find('[data-mypa-bulk-date-value]').val()) {
                    params['changes[delivery_date]'] = $root.find('[data-mypa-bulk-date-value]').val();
                }

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
            add($scope.find('[data-mypa-option]').not(':disabled'), '[options]');
            add($root.find('[data-mypa-order-field]'), '');

            return params;
        }

        /**
         * @param {Object}   config
         * @param {String}   config.formUrl
         * @param {String}   config.saveUrl
         * @param {String[]} config.ids
         * @param {String}   config.idType    - 'order' or 'shipment'
         * @param {String}   config.title
         * @param {String}   [config.extraHtml] - shown below the options, for the export settings
         * @param {Array}    config.buttons   - {text, class, click(api)}
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
            var api;

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
                            button.click(api);
                        }
                    };
                }),
                closed: function () {
                    $content.remove();
                }
            }, $content);

            $content.modal('openModal');

            $footer = $content.closest('.modal-inner-wrap').find('.modal-footer').hide();

            post(config.formUrl, idParams(config.ids, config.idType))
                .catch(function () {
                    return {error: $.mage.__('The MyParcel options could not be loaded.')};
                })
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
                    });
                    $root.on('change', '[data-mypa-no-date]', function () {
                        var $date = $root.find('[data-mypa-field="delivery_date"]');

                        $date.prop('disabled', this.checked).val(this.checked ? '' : $date.attr('data-initial'));
                    });
                    $root.on('change', '[data-mypa-bulk-date]', function () {
                        $root.find('[data-mypa-bulk-date-value]').toggle('set' === $(this).val());
                    });

                    if (!isBulk($root)) {
                        toggle($root);
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
