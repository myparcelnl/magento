define(
    [
        'jquery',
        'MyParcelNL_Magento/js/shipment-options',
        'text!MyParcelNL_Magento/template/grid/order_massaction.html',
        'Magento_Ui/js/modal/alert',
        'uiRegistry',
        'MyParcelNL_Magento/js/admin-messages',
        'MyParcelNL_Magento/js/label-download',
        'mage/loader',
        'mage/translate'
    ],
    function ($, shipmentOptions, template, alert, registry, messages, downloadLabels) {
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

                    // The order and shipment pages show this button beside the shipping information.
                    $(document).on('click', '.action-myparcel-options', function () {
                        parentThis._showMyParcelModal();
                    });

                    if (this.options['button_send_return_mail_present']) {
                        $('.action-myparcel_send_return_mail').on(
                            "click",
                            function () {
                                parentThis._setSelectedIds();
                                // Joined with a comma, which is what the controller splits on.
                                parentThis._runExport(
                                    parentThis.options.url_send_return_mail,
                                    null,
                                    {selected_ids: parentThis.selectedIds.join(',')}
                                );
                            }
                        );
                    }

                    if (this.options['button_present']) {
                        // The order and shipment pages print at once; their options button opens the modal.
                        $('.action-myparcel').on(
                            "click",
                            function () {
                                parentThis._printDirectly();
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
                 * The shipment options of the selection, with the export settings below them.
                 *
                 * @param {String[]} [ids] - one row's id; the page's selection when absent
                 * @protected
                 */
                _showMyParcelModal: function (ids) {
                    var parentThis = this;

                    if (ids) {
                        this.selectedIds = ids;
                    } else {
                        this._setSelectedIds();
                    }

                    this._translateTemplate();

                    if (this.selectedIds.length == 0) {
                        alert({title: $.mage.__('Please select an item from the list')});

                        return this;
                    }

                    if (('has_api_key' in this.options) && (false === this.options['has_api_key'])) {
                        alert({title: $.mage.__('No key found. Go to Configuration and then to MyParcel to enter the key.')});

                        return this;
                    }

                    shipmentOptions.open({
                        formUrl: this.options.url_options_form,
                        saveUrl: this.options.url_options_save,
                        ids: this.selectedIds,
                        idType: this.options.id_type || 'order',
                        title: $.mage.__('MyParcel options'),
                        extraHtml: template,
                        buttons: [
                            {
                                text: $.mage.__('Save'),
                                class: 'action-secondary',
                                click: function (api) {
                                    return api.save().then(function () {
                                        api.close();
                                        parentThis._refresh(false);
                                    }).catch(function () {
                                        // api.save() shows why in the modal.
                                    });
                                }
                            },
                            {
                                text: $.mage.__('Save & export'),
                                class: 'action-primary',
                                click: function (api) {
                                    // Opened here because this is still the click. A tab opened once
                                    // the labels arrive is a popup, and the browser blocks it.
                                    var tab = $('#mypa_request_type-open_new_tab').prop('checked')
                                        ? window.open('', '_blank')
                                        : null;

                                    return api.save().then(function () {
                                        // Read before closing: closing removes the form.
                                        var params = $('#mypa-options-form').serialize();

                                        api.close();
                                        parentThis._runExport(parentThis.options.url, tab, params);
                                    }).catch(function () {
                                        if (tab) {
                                            tab.close();
                                        }
                                    });
                                }
                            }
                        ]
                    });

                    $('#selected_ids').val(this.selectedIds.join(','));
                    this
                        ._setMyParcelMassActionObserver()
                        ._setActions()
                        ._setDefaultSettings()
                        ._showMyParcelOptions();

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

                    // Delegated: the options form, which holds the label amount, arrives later.
                    $(document).off('change.mypaLabelAmount').on(
                        'change.mypaLabelAmount',
                        '[data-mypa-field="label_amount"]',
                        function () {
                            parentThis._setLabelPosition(parseInt($(this).val(), 10) || 1);
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
                 * Prints the page's order or shipment with the configured settings, creating its
                 * shipment and concept first when it has none.
                 *
                 * @protected
                 */
                _printDirectly: function () {
                    this._setSelectedIds();

                    if (('has_api_key' in this.options) && (false === this.options['has_api_key'])) {
                        alert({title: $.mage.__('No key found. Go to Configuration and then to MyParcel to enter the key.')});

                        return;
                    }

                    this._runExport(this.options.url, null, {
                        selected_ids: this.selectedIds.join(','),
                        mypa_request_type: 'download'
                    });
                },

                /**
                 * One row's "Change MyParcel options", named as a callback by TrackActions.
                 *
                 * @protected
                 */
                openOptionsRow: function (actionIndex, recordId) {
                    this._showMyParcelModal([String(recordId)]);
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

                    this._runExport(action.url, null, {selected_ids: ids.join(',')});
                },

                /**
                 * One row's action that answers export JSON, named as a callback by TrackActions.
                 * The URL it was given already carries that order's id and whatever else the action
                 * needs, so nothing is added to the body but the form key.
                 *
                 * @protected
                 */
                exportRow: function (actionIndex, recordId, action) {
                    this._runExport(action.href, null);
                },

                /**
                 * POSTed, never GETed: the export creates billable shipments and can mail the
                 * customer, and a bulk selection is long enough to push an admin URL past what a
                 * web server accepts. An admin POST is also form-key checked, where a GET is not.
                 *
                 * @param {String}                 url    - the export.
                 * @param {Window|null}            tab    - a tab opened during the click, for the
                 *                                          PDF to fill.
                 * @param {Object|String}          params - what the export needs, for the body. A
                 *                                          ready-made href carries its own query
                 *                                          and passes none.
                 *
                 * @protected
                 */
                _runExport: function (url, tab, params) {
                    var parentThis = this;
                    var failed = false;

                    messages.clear();
                    $('body').trigger('processStart');

                    shipmentOptions.post(url, params)
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
