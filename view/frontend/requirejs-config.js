/* eslint-disable no-unused-vars,no-var -- Magento merges these files by their var config. */

/**
 * Override Magento classes.
 *
 * @type {Object}
 */
var config = {
  config: {
    mixins: {
      'Magento_Checkout/js/view/shipping': {'MyParcelNL_Magento/js/view/shipping': true},
      'Magento_Checkout/js/view/summary/shipping': {'MyParcelNL_Magento/js/view/shipping-summary': true},
    },
  },
  paths: {
    myparcelDeliveryOptions: 'https://cdn.jsdelivr.net/npm/@myparcel-dev/delivery-options@7/dist/myparcel',
    leaflet: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.5.1/leaflet',
  },
};
