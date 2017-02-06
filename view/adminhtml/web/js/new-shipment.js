define(
    ['jquery'],
    function ($) {
        'use strict';

        return function NewShipment(options, element)
        {

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
                    $("input[name='mypa[package_type]']").on(
                        "change",
                        function () {
                            if ($('#mypa_package_type-package').prop('checked')) {
                                $('.mypa_package-toggle').show();
                            } else {
                                $('.mypa_package-toggle').hide();
                            }
                        }
                    );

                    return this;
                }
            };

            model.initialize(options, element);
            return model;
        };
    }
);