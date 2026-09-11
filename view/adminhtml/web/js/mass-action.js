define(
    [
        'jquery',
        'Magento_Ui/js/modal/confirm',
        'text!MyParcelNL_Magento/template/grid/order_massaction.html',
        'Magento_Ui/js/modal/alert',
        'uiRegistry',
        'MyParcelNL_Magento/js/admin-messages',
        'MyParcelNL_Magento/js/label-download',
        'mage/loader',
        'mage/translate'
    ],
    function ($, confirmation, template, alert, registry, messages, downloadLabels) {
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
                    // The native mass action in sales_order_grid.xml names this, so that entry point
                    // runs the export the same way instead of submitting a form at the controller.
                    registry.set('myparcel_grid_massaction', this);
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
                                    parentThis._createConsignment();
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
                 * Exports without leaving the page, so the grid keeps its selection and fetches its
                 * rows once — after the labels have minted the barcodes rather than against them.
                 *
                 * @protected
                 */
                _createConsignment: function () {
                    // Opened here because this is still the click. A tab opened once the labels
                    // arrive is a popup, and the browser blocks it.
                    var tab = $('#mypa_request_type-open_new_tab').prop('checked')
                        ? window.open('', '_blank')
                        : null;

                    this._runExport(this.options.url + '?' + $("#mypa-options-form").serialize(), tab);
                },

                /**
                 * The grid's own "Print MyParcel labels directly" action, which skips the modal and
                 * exports with the configured defaults. Named as a callback by sales_order_grid.xml,
                 * so it arrives here with the grid's selection rather than as a form submit.
                 *
                 * request_type defaults to download, so there is no tab to open.
                 *
                 * @protected
                 */
                exportSelected: function (action, data) {
                    var ids = data.selected || [];

                    // Selecting every page sets excludeMode and no ids, which this cannot express.
                    if (!ids.length) {
                        alert({title: $.mage.__('Please select an item from the list')});

                        return;
                    }

                    this._runExport(action.url + '?selected_ids=' + ids.join(','), null);
                },

                /**
                 * One row's export action, named as a callback by TrackActions. The URL it was given
                 * already carries that order's id and package type.
                 *
                 * @protected
                 */
                exportRow: function (actionIndex, recordId, action) {
                    this._runExport(action.href, null);
                },

                /**
                 * One row's "Download label", named as a callback by TrackActions. Not _runExport:
                 * the happy answer here is the PDF itself, which response.json() would destroy.
                 * downloadLabels reads the stream and reports a JSON failure beside the grid.
                 *
                 * @protected
                 */
                downloadLabelRow: function (actionIndex, recordId, action) {
                    var parentThis = this;

                    messages.clear();
                    $('body').trigger('processStart');

                    downloadLabels({
                        url: action.href,
                        failureLabel: $.mage.__('The MyParcel labels could not be downloaded.')
                    }, null)
                        .then(function (delivered) {
                            // The label fetch mints the barcodes, so the grid has new data to show.
                            parentThis._refresh(!delivered);
                        })
                        .finally(function () {
                            $('body').trigger('processStop');
                        });
                },

                /**
                 * @param {String}      url - the export, with its options already in the query.
                 * @param {Window|null} tab - a tab opened during the click, for the PDF to fill.
                 *
                 * @protected
                 */
                _runExport: function (url, tab) {
                    var parentThis = this;
                    var failed = false;

                    messages.clear();
                    $('body').trigger('processStart');

                    fetch(url, {credentials: 'same-origin'})
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (answer) {
                            failed = messages.render(answer.messages);

                            if (!answer.labels) {
                                if (tab) {
                                    tab.close();
                                }

                                return null;
                            }

                            return downloadLabels(answer.labels, tab).then(function (delivered) {
                                failed = failed || !delivered;
                            });
                        })
                        .catch(function () {
                            // A dropped connection, or an error page where JSON was expected.
                            if (tab) {
                                tab.close();
                            }

                            failed = true;
                            messages.error($.mage.__('The MyParcel export could not be completed.'));
                        })
                        .finally(function () {
                            parentThis._refresh(failed);
                            $('body').trigger('processStop');
                        });
                },

                /**
                 * Shows what the export just changed.
                 *
                 * On a grid, refresh: true is not optional — without it the provider serves its
                 * cached rows and the export appears to have changed nothing.
                 *
                 * A single order or shipment page has no grid, so it reloads instead. Not after a
                 * failure: the reload would take the message explaining it along too.
                 *
                 * @protected
                 */
                _refresh: function (failed) {
                    // The messages sit at the top of the page, and an export is started from a row
                    // that can be well below the fold.
                    window.scrollTo({top: 0, behavior: 'smooth'});

                    if (this.options['grid_data_source']) {
                        registry.get(this.options['grid_data_source'], function (provider) {
                            provider.reload({refresh: true});
                        });

                        return;
                    }

                    if (!failed) {
                        window.location.reload();
                    }
                }
            };

            model.initialize(options, element);
            return model;
        };
    }
);
