define(
    [
        'jquery',
        'Magento_Ui/js/modal/confirm',
        'text!MyParcelBE_Magento/template/grid/order_massaction.html',
        'Magento_Ui/js/modal/alert',
        'loadingPopup',
        'mage/translate'
    ],
    function ($, confirmation, template, alert) {
        'use strict';

        return function MassAction(
            options,
            element
        ) {

            var model = {

                /**
                 * Initializes observable properties.
                 *
                 * @returns {MassAction} Chainable.
                 */
                initialize: function (options, element) {
                    this.options = options;
                    this.element = element;
                    this.selectedIds = [];
                    this._setMyParcelMassAction();
                    return this;
                },

                /**
                 * Set MyParcel Mass action button
                 *
                 * @protected
                 */
                _setMyParcelMassAction: function () {
                    var massSelectorLoadInterval;
                    var parentThis = this;

                    if (this.options['button_send_return_mail_present']) {
                        $('.action-myparcel_send_return_mail').on(
                            "click",
                            function () {
                                parentThis._setSelectedIds();
                                window.location.href = parentThis.options.url_send_return_mail + '?selected_ids=' + parentThis.selectedIds.join(';');
                            }
                        );
                    }

                    if (this.options['button_present']) {
                        $('.action-myparcel').on(
                            "click",
                            function () {
                                parentThis._showMyParcelModal();
                            }
                        );
                    } else {
                        /* In order grid, button don't exist. Append a button */
                        massSelectorLoadInterval = setInterval(
                            function () {
                                var actionSelector = $('.action-select-wrap .action-menu');
                                if (actionSelector.length) {
                                    clearInterval(massSelectorLoadInterval);
                                    actionSelector.append(
                                        '<li><span class="action-menu-item action-myparcel">Print MyParcel labels</span></li>'
                                    );

                                    $('.action-myparcel').on(
                                        "click",
                                        function () {
                                            parentThis._showMyParcelModal();
                                        }
                                    );
                                }
                            },
                            1000
                        );
                    }
                },

                /**
                 * Show MyParcel options
                 *
                 * @protected
                 */
                _showMyParcelModal: function () {
                    var parentThis = this;
                    parentThis
                        ._setSelectedIds()
                        ._translateTemplate();

                    if (this.selectedIds.length == 0) {
                        alert({title: $.mage.__('Please select an item from the list')});

                        return this;
                    }

                    if (('has_api_key' in this.options) && (false === this.options['has_api_key'])) {
                        alert({title: $.mage.__('No key found. Go to Configuration and then to MyParcel to enter the key.')});

                        return this;
                    }

                    confirmation(
                        {
                            title: $.mage.__('MyParcel options'),
                            content: template,
                            focus: function () {
                                $('#selected_ids').val(parentThis.selectedIds.join(','));
                                parentThis
                                    ._setMyParcelMassActionObserver()
                                    ._setActions()
                                    ._setDefaultSettings()
                                    ._showMyParcelOptions();
                            },
                            actions: {
                                confirm: function () {
                                    parentThis
                                        ._startLoading()
                                        ._createConsignment();
                                }
                            }
                        }
                    );

                    if (parentThis._usePPSExportMode()) {
                      $('#mypa_container-request_type').hide();
                      $('#mypa_container-label_amount').hide();
                      $('#mypa_container-print_position').hide();
                    }
                },

                /**
                 * Translate html templates
                 **/
                _translateTemplate: function () {
                    /*
                    Magento only index these variables in js-translation if you define
                    $.mage.__('Action type');
                    $.mage.__('Download label');
                    $.mage.__('Open in new tab');
                    $.mage.__('Concept');
                    $.mage.__('Package Type');
                    $.mage.__('Default');
                    $.mage.__('Package');
                    $.mage.__('Print position');
                    */

                    $($.parseHTML(template)).find("[trans]").each(function (index) {
                        var oldElement = $(this).get(0).outerHTML;
                        var newElement = $(this).html($.mage.__($(this).attr('trans'))).get(0).outerHTML;
                        template = template.replace(oldElement, newElement);
                    });
                },

                /**
                 * Set actions
                 *
                 * @protected
                 */
                _setActions: function () {
                    var parentThis = this;
                    var actionOptions = ['request_type', 'package_type', 'print_position', 'label_amount', 'carrier'];

                    actionOptions.forEach(function (option) {
                        if (!(option in parentThis.options['action_options']) || (parentThis.options['action_options'][option] == false)) {
                            $('#mypa_container-' + option).hide();
                        }
                    });

                    return this;
                },



                /**
                 * Set default settings
                 *
                 * @protected
                 */
                _setDefaultSettings: function () {
                    var selectAmount;

                    if ('number_of_positions' in this.options) {
                        selectAmount = this.options['number_of_positions'];
                    } else {
                        selectAmount = this.selectedIds.length;
                    }

                    $('#mypa_request_type-download').prop('checked', true).trigger('change');
                    $('#mypa_package_type-default').prop('checked', true).trigger('change');
                    $('#mypa_carrier_default').prop('checked', true).trigger('change');
                    $('#paper_size-' + this.options.settings['paper_type']).prop('checked', true).trigger('change');

                    this._getLabelPosition(selectAmount);

                    return this;
                },

                /**
                 * Show options
                 *
                 * @protected
                 */
                _showMyParcelOptions: function () {
                    $('div#mypa-options').addClass('_active');

                    return this;
                },

                /**
                 * MyParcel action observer
                 *
                 * @protected
                 */
                _setMyParcelMassActionObserver: function () {
                    var parentThis = this;

                    $("input[name='mypa_paper_size']").on(
                        "change",
                        function () {
                            if ($('#paper_size-A4').prop('checked')) {
                                $('.mypa_position_selector').addClass('_active');
                            } else {
                                $('.mypa_position_selector').removeClass('_active');
                            }
                        }
                    );

                    $("input[name='mypa_request_type']").on(
                        "change",
                        function () {
                            if ($('#mypa_request_type-concept').prop('checked')) {
                                $('.mypa_position_container').hide();
                            } else {
                                $('.mypa_position_container').show();
                            }
                        }
                    );

                  $("input[name='mypa_carrier']").on(
                    'change',
                    function() {
                      if ($('#mypa_carrier_postnl').prop('checked')) {
                        $('#mypa_container-package_type-digital_stamp').show();
                        $('#mypa_container-package_type-letter').show();
                      } else {
                        $('#mypa_container-package_type-digital_stamp').hide();
                        $('#mypa_container-package_type-letter').hide();
                      }
                    }
                  );

                    $("select[name='mypa_label_amount']").on(
                        "change",
                        function () {
                            var selectAmount = parseInt($("select[name='mypa_label_amount']").val());
                            parentThis._setLabelPosition(selectAmount);
                        }
                    );

                    return this;
                },

                /**
                 * @protected
                 */
                _setLabelPosition: function (selectAmount) {
                    var totalAmount = selectAmount * this.selectedIds.length;
                    $("input[id^=mypa_position-]").prop('checked', false);

                    this._getLabelPosition(totalAmount);
                },

                /**
                 * @protected
                 */
                _getLabelPosition: function (selectAmount) {
                    if (selectAmount != 0) {
                        if (selectAmount >= 1) {
                            $('#mypa_position-2').prop('checked', true);
                        }

                        if (selectAmount >= 2) {
                            $('#mypa_position-4').prop('checked', true);
                        }

                        if (selectAmount >= 3) {
                            $('#mypa_position-1').prop('checked', true);
                        }

                        if (selectAmount >= 4) {
                            $('#mypa_position-3').prop('checked', true);
                        }
                    }
                },

                /**
                 * @protected
                 */
                _usePPSExportMode: function () {
                  var exportMode = this.options.settings['export_mode'];

                  return exportMode === 'pps';
                },

                /**
                 * Create consignment
                 *
                 * @protected
                 */
                _setSelectedIds: function () {
                    var parentThis = this;
                    var oneOrderIdSelector = $('input[name="order_id"]');
                    this.selectedIds = [];
                    if (oneOrderIdSelector.length) {
                        parentThis.selectedIds.push(oneOrderIdSelector.attr('value'));
                        return this;
                    }

                    if ('entity_id' in parentThis.options) {
                        parentThis.selectedIds.push(parentThis.options['entity_id']);
                        return this;
                    }

                    $('.data-grid-checkbox-cell-inner input.admin__control-checkbox:checked').each(
                        function () {
                            parentThis.selectedIds.push($(this).attr('value'));
                        }
                    );

                    return this;
                },

                /**
                 * Create consignment
                 *
                 * @protected
                 */
                _createConsignment: function () {
                    var url = this.options.url + '?' + $("#mypa-options-form").serialize();
                    if ($('#mypa_request_type-open_new_tab').prop('checked')) {
                        window.open(url);
                    } else {
                        window.location.href = url;
                    }
                },

                _startLoading: function () {
                    $('body').loadingPopup();
                    return this;
                }
            };

            model.initialize(options, element);
            return model;
        };
    }
);
