define([
    'mage/utils/wrapper',
    'MyParcelNL_Magento/js/model/shipping-save-processor/myParcel'
], function (wrapper, after) {
    'use strict';

    return function (payloadExtender) {

        return wrapper.wrap(payloadExtender, function (_super, payload) {
            var original = _super(payload); // call original method

            var result = payload;

            //if multiple wrappers are defined, the original function can return 'undefined'. we don't want that.
            if (typeof original !== 'undefined') {
                result = original;
            }

            return after(result); // the 'after' plugin
        });
    }
});
