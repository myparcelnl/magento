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
                                parentThis._checkOptionsField();

                            } else {
                                $('.mypa-option-toggle').slideUp();
                                $('.mypa_carrier-toggle').slideUp();
                            }
                        }
                    );

                    if (carrierAmount <= 1) {
                        parentThis._checkOptionsField();
                    }

                    $("#mypa_carrier_postnl").click(
                        function () {
                            console.log('sdasd');
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

                _checkOptionsField: function () {
                    if ($('#mypa_carrier_postnl').prop("checked", true)) {
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
