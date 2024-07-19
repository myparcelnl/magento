/**
 * Extends Magento_Checkout/js/view/shipping.
 */

define([
  'ko',
  'MyParcelBE_Magento/js/view/delivery-options',
  'MyParcelBE_Magento/js/model/checkout',
], function(
  ko,
  deliveryOptions,
  checkout
) {
  'use strict';

  /**
   * Extend, add or modify any functionality in this object.
   *
   * @type {Object}
   */
  var mixin = {
    /**
     * Override the initialize module, using it to add new properties to the original module. Without this they can't
     *  be called from the shipping method item template, for example.
     */
    initialize: function() {
      this._super();

      checkout.rates = this.rates;
      checkout.initialize();

      /**
       * Subscribe to the hasDeliveryOptions boolean. If it is true, initialize the delivery options module.
       */
      checkout.hasDeliveryOptions.subscribe(function(enabled) {
        checkout.hideShippingMethods();

        if (enabled) {
          deliveryOptions.initialize();
        } else {
          deliveryOptions.destroy();
        }
      });
    },
  };

  /**
   * Return the original module, extended by our mixin.
   *
   * @param {function} targetModule - The extended module.
   *
   * @returns {*}
   */
  return function(targetModule) {
    return targetModule.extend(mixin);
  };
});
