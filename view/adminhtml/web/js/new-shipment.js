/**
 * The MyParcel part of the New Shipment page: the export switch, and the options modal. A save
 * stores the changes on the order, and the page shows the summary the server answers.
 */
define(
    [
        'jquery',
        'MyParcelNL_Magento/js/shipment-options',
        'mage/translate'
    ],
    function ($, shipmentOptions) {
        'use strict';

        /** @param {Object} summary - label => value */
        function renderSummary(summary) {
            var $summary = $('[data-mypa-summary]').empty();

            Object.keys(summary).forEach(function (label) {
                $summary.append($('<dt></dt>').text(label), $('<dd></dd>').text(summary[label]));
            });
        }

        return function NewShipment(config) {
            $('#mypa_create_from_observer').on('change', function () {
                $('.js--mypa-options').toggle($(this).prop('checked'));
            });

            $('[data-mypa-change-options]').on('click', function () {
                shipmentOptions.open({
                    formUrl: config.formUrl,
                    saveUrl: config.saveUrl,
                    ids: [String(config.orderId)],
                    idType: 'order',
                    title: $.mage.__('MyParcel options'),
                    buttons: [
                        {
                            text: $.mage.__('Save'),
                            class: 'action-primary',
                            click: function (api) {
                                api.save().then(function (answer) {
                                    if (answer.summary) {
                                        renderSummary(answer.summary);
                                    }

                                    api.close();
                                }).catch(function () {
                                    // api.save() shows why in the modal.
                                });
                            }
                        }
                    ]
                });
            });
        };
    }
);
