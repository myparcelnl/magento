/* eslint-disable max-len,no-unused-vars */

/**
 * Override Magento classes.
 *
 * @type {Object}
 */
var config = {
  config: {
    mixins: {
      'Magento_Checkout/js/view/shipping': {'MyParcelBE_Magento/js/view/shipping': true},
    },
  },
  map: {
    '*': {
      'Magento_Checkout/js/model/shipping-save-processor/default': 'MyParcelBE_Magento/js/model/shipping-save-processor-default',
    },
  },
  paths: {
    leaflet: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.5.1/leaflet',
    vue2leaflet: 'https://cdnjs.cloudflare.com/ajax/libs/Vue2Leaflet/1.0.2/vue2-leaflet.min',
  },
};
