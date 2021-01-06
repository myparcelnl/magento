define(
    ['jquery'],
    function ($) {
        'use strict';

        return function NewShipment(options, element) {
            var model = {

                /**
                 * Initializes observable properties.
                 *
                 * @returns {NewShipment} Chainable.
                 */
                initialize: function (options, element) {
                    this.options = options;
                    this.element = element;
                    this._setOptionsObserver();
                    return this;
                },

                /**
                 * MyParcel action observer
                 *
                 * @protected
                 */
                _setOptionsObserver: function () {
                    var parentThis = this;
                    var carrierAmount = $(':input[name="mypa_carrier"]').length;

                    $("input[name='mypa_create_from_observer']").on(
                        "change",
                        function () {
                            if ($('#mypa_create_from_observer').prop('checked')) {
                                $('.mypa_carrier-toggle').slideDown();
                                parentThis._checkCarrierField();
                                parentThis._checkPackageTypeField();

                            } else {
                                $('.mypa-option-toggle').slideUp();
                                $('.mypa_package-toggle').slideUp();
                                $('.mypa_carrier-toggle').slideUp();
                            }
                        }
                    );

                    if (carrierAmount <= 1) {
                        parentThis._checkPackageTypeField();
                    }

                    $("#mypa_carrier_postnl").click(
                        function () {
                            parentThis._checkPackageTypeField();
                        }
                    );

                    $("input[name='mypa_package_type']").on(
                        "change",
                        function () {
                            parentThis._checkOptionsField();
                        }
                    );

                    $("input[name='mypa_carrier']").on(
                        "change",
                        function () {
                            parentThis._checkCarrierField();
                        }
                    );

                    return this;
                },

                _checkPackageTypeField: function () {
                    if ($('#mypa_carrier_postnl').prop("checked", true)) {
                        $('.mypa_package-toggle').slideDown();
                    }
                },

                _checkOptionsField: function () {
                    if ($('#mypa_package_type').prop("checked", true)) {
                        $('.mypa-option-toggle').slideDown();
                    }
                },

                _checkCarrierField: function () {
                    $('.mypa_carrier-toggle').show();
                }
            };

            model.initialize(options, element);
            return model;
        };
    }
);
