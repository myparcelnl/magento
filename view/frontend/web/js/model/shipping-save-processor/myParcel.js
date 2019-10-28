define(
    [
        'jquery'
    ],
    function (
        $
    ) {
        'use strict';

        return function(payload) {

            var deliveryOptions = $('[name="delivery_options"]').val();

            payload['addressInformation']['extension_attributes']['delivery_options'] = deliveryOptions;
        }
    }
);
