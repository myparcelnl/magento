define([
    'jquery'
], function ($) {
    'use strict';
    console.info('myparcel options load');
    //console.info(options);
    //console.info(element);

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

                $("input[name='paper_size']").on("change", function () {
                    if ($('#paper_size-A4').prop('checked')) {
                        $('.mypa_position_selector').addClass('_active');
                    } else {
                        $('.mypa_position_selector').removeClass('_active');
                    }
                });

                $("input[name='mypa_request_type']").on("change", function () {
                    if ($('#mypa_request_type-download').prop('checked')) {
                        $('.mypa_position_container').show();
                    } else {
                        $('.mypa_position_container').hide();
                    }
                });
                return this;
            },
        };

        model.initialize(options, element);
        return model;
    };
});