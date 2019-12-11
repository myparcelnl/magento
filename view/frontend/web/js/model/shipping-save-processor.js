define([
    'mage/utils/wrapper'
], function (wrapper, after) {
    'use strict';

    return function (payloadExtender) {
        return wrapper.wrap(payloadExtender, function (_super, payload) {
            // call original method
            var original = _super(payload);

            var result = payload;

            //if multiple wrappers are defined, the original function can return 'undefined'. we don't want that.
            if (typeof original !== 'undefined') {
                result = original;
            }

            var deliveryOptions = document.querySelector('[name="delivery_options"]').value;

            result['addressInformation']['extension_attributes']['delivery_options'] = deliveryOptions;
       
            return result;
        });
    }
});
