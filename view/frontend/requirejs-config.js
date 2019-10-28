/**
 * Override Magento classes
 *
 * @type {{map: {"*": {"Magento_Tax/js/view/checkout/shipping_method/price": string, "Magento_Checkout/js/model/shipping-save-processor/default": string}}}}
 */
var config = {
    map: {
        '*': {
            'Magento_Tax/js/view/checkout/shipping_method/price': 'MyParcelNL_Magento/js/action/price',
        }
    },
    config: {
        mixins: {
            'Magento_Checkout/js/model/shipping-save-processor/payload-extender': {
                'MyParcelNL_Magento/js/mixins/checkout/model/shipping-save-processor/payload-extender-mixin': true
            }
        }
    }
};
