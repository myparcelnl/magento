define(['MyParcelNL_Magento/js/view/delivery-options'], function (deliveryOptions) {
  'use strict';

  /**
   * Extend, add or modify any functionality in this object.
   *
   * @type {Object}
   */
  const mixin = {

    /**
     * Override the getShippingMethodTitle function to retrieve the correct shipping method title when using MyParcel.
     *
     * @returns {*}
     */
    getShippingMethodTitle: function () {
      if (deliveryOptions.isUsingMyParcelMethod) {
        try {
          const shippingMethod = JSON.parse(localStorage.getItem(deliveryOptions.localStorageKey));

          if (shippingMethod && shippingMethod.hasOwnProperty('method_title')) {
            return shippingMethod.method_title;
          }
        } catch (e) {
          // default to original title
        }
      }

      return this._super();
    },
  };

  /**
   * Return the original module, extended by our mixin.
   *
   * @param {function} targetModule - The extended module.
   *
   * @returns {*}
   */
  return function (targetModule) {
    return targetModule.extend(mixin);
  };
});
