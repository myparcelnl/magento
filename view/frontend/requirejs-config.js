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
    myparcelDeliveryOptions: 'https://cdn.jsdelivr.net/npm/@myparcel/delivery-options@6/dist/myparcel',
    leaflet: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.5.1/leaflet',
  },
};
