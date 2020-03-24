define(
  [
    'underscore',
    'ko',
    'Magento_Checkout/js/model/shipping-rate-registry',
    'Magento_Checkout/js/model/quote',
    'MyParcelBE_Magento/js/model/checkout',
    'MyParcelBE_Magento/js/polyfill/array_prototype_find',
    'MyParcelBE_Magento/js/vendor/myparcel',
    'MyParcelBE_Magento/js/vendor/polyfill-custom-event',
    'leaflet',
    'vue2leaflet',
  ],
  function(
    _,
    ko,
    shippingRateRegistry,
    quote,
    checkout,
    array_prototype_find,
    myparcel,
    CustomEvent,
    leaflet,
    vue2leaflet
  ) {
    'use strict';

    // HACK: without this the pickup locations map doesn't work as RequireJS messes with any global variables.
    window.Vue2Leaflet = vue2leaflet;

    var deliveryOptions = {
      rendered: ko.observable(false),

      splitStreetRegex: /(.*?)\s?(\d{1,4})[/\s-]{0,2}([A-z]\d{1,3}|-\d{1,4}|\d{2}\w{1,2}|[A-z][A-z\s]{0,3})?$/,

      disableDeliveryOptionsEvent: 'myparcel_disable_delivery_options',
      hideDeliveryOptionsEvent: 'myparcel_hide_delivery_options',
      renderDeliveryOptionsEvent: 'myparcel_render_delivery_options',
      showDeliveryOptionsEvent: 'myparcel_show_delivery_options',
      updateDeliveryOptionsEvent: 'myparcel_update_delivery_options',

      updatedDeliveryOptionsEvent: 'myparcel_updated_delivery_options',
      updatedAddressEvent: 'myparcel_updated_address',

      /**
       * The selector of the field we use to get the delivery options data into the order.
       *
       * @type {String}
       */
      hiddenDataInput: '[name="myparcel_delivery_options"]',

      /**
       * Initialize the script. Render the delivery options div, request the plugin settings, then initialize listeners.
       */
      initialize: function() {
        window.MyParcelConfig.address = deliveryOptions.getAddress(quote.shippingAddress());
        deliveryOptions.render();
        deliveryOptions.addListeners();

        deliveryOptions.rendered.subscribe(function(bool) {
          if (bool) {
            deliveryOptions.updateAddress();
          }
        });
      },

      destroy: function() {
        deliveryOptions.triggerEvent(deliveryOptions.hideDeliveryOptionsEvent);
        document.querySelector(deliveryOptions.hiddenDataInput).value = '';

        document.removeEventListener(
          deliveryOptions.updatedDeliveryOptionsEvent,
          deliveryOptions.onUpdatedDeliveryOptions
        );
      },

      /**
       * Create the div the delivery options will be rendered in, if it doesn't exist yet.
       */
      render: function() {
        var hasUnrenderedDiv = document.querySelector('#myparcel-delivery-options');
        var hasRenderedDeliveryOptions = document.querySelector('.myparcel-delivery-options__table');
        var shippingMethodDiv = document.querySelector('#checkout-shipping-method-load');
        var deliveryOptionsDiv = document.createElement('div');

        deliveryOptions.rendered(false);

        /**
         * Sometimes the shipping method div doesn't exist yet. Retry in 100ms if it happens.
         */
        if (!shippingMethodDiv) {
          setTimeout(function() {
            deliveryOptions.render();
          }, 100);
          return;
        }

        if (hasUnrenderedDiv || hasRenderedDeliveryOptions) {
          deliveryOptions.triggerEvent(deliveryOptions.updateDeliveryOptionsEvent);
        } else if (!hasUnrenderedDiv) {
          deliveryOptionsDiv.setAttribute('id', 'myparcel-delivery-options');
          shippingMethodDiv.insertBefore(deliveryOptionsDiv, shippingMethodDiv.firstChild);
          deliveryOptions.triggerEvent(deliveryOptions.renderDeliveryOptionsEvent);
        }

        deliveryOptions.rendered(true);
      },

      /**
       * Add event listeners to shipping methods and address as well as the delivery options module.
       */
      addListeners: function() {
        quote.shippingAddress.subscribe(deliveryOptions.updateAddress);
        quote.shippingMethod.subscribe(_.debounce(deliveryOptions.onShippingMethodUpdate));

        document.addEventListener(
          deliveryOptions.updatedDeliveryOptionsEvent,
          deliveryOptions.onUpdatedDeliveryOptions
        );
      },

      /**
       * Run the split street regex on the given full address to extract the house number and return it.
       *
       * @param {String} address - Full address.
       *
       * @returns {String|undefined} - The house number, if found. Otherwise null.
       */
      getHouseNumber: function(address) {
        var result = this.splitStreetRegex.exec(address);
        var numberIndex = 2;
        return result ? result[numberIndex] : null;
      },

      /**
       * Trigger an event on the document body.
       *
       * @param {String} identifier - Name of the event.
       */
      triggerEvent: function(identifier) {
        var event = document.createEvent('HTMLEvents');
        event.initEvent(identifier, true, false);
        document.querySelector('body').dispatchEvent(event);
      },

      /**
       * Get address data and put it in the global MyParcelConfig.
       *
       * @param {Object?} address - Quote.shippingAddress from Magento.
       */
      updateAddress: function(address) {
        window.MyParcelConfig.address = deliveryOptions.getAddress(address || quote.shippingAddress());

        deliveryOptions.triggerEvent(deliveryOptions.updateDeliveryOptionsEvent);
      },

      /**
       * Get the address entered by the user depending on if they are logged in or not.
       *
       * @returns {Object}
       * @param {Object} address - Quote.shippingAddress from Magento.
       */
      getAddress: function(address) {
        return {
          number: address.street ? deliveryOptions.getHouseNumber(address.street.join(' ')) : '',
          cc: address.countryId || '',
          postalCode: address.postcode || '',
          city: address.city || '',
        };
      },

      /**
       * Triggered when the delivery options have been updated. Put the received data in the created data input. Then
       *  do the request that tells us which shipping method needs to be selected.
       *
       * @param {CustomEvent} event - The event that was sent.
       */
      onUpdatedDeliveryOptions: function(event) {
        deliveryOptions.deliveryOptions = event.detail;
        document.querySelector(deliveryOptions.hiddenDataInput).value = JSON.stringify(event.detail);

        /**
         * If the delivery options were emptied, don't request a new shipping method.
         */
        if (JSON.stringify(deliveryOptions.deliveryOptions) === '{}') {
          return;
        }

        checkout.convertDeliveryOptionsToShippingMethod(event.detail, {
          onSuccess: function(response) {
            quote.shippingMethod(deliveryOptions.getNewShippingMethod(response[0].element_id));
          },
        });
      },

      /**
       * Change the shipping method and disable the delivery options if needed.
       *
       * @param {Object} selectedShippingMethod - The shipping method that was selected.
       */
      onShippingMethodUpdate: function(selectedShippingMethod) {
        var newShippingMethod = selectedShippingMethod || {};
        var available = newShippingMethod.available || false;
        var methodEnabled = checkout.allowedShippingMethods().indexOf(newShippingMethod.method_code) > -1;
        var isMyParcelMethod = available ? newShippingMethod.method_code.indexOf('myparcel') > -1 : false;

        checkout.hideShippingMethods();

        if (!checkout.hasDeliveryOptions()) {
          return;
        }

        if (!available) {
          return;
        }

        if (JSON.stringify(deliveryOptions.shippingMethod) !== JSON.stringify(newShippingMethod)) {
          deliveryOptions.shippingMethod = newShippingMethod;

          if (!isMyParcelMethod && !methodEnabled) {
            deliveryOptions.triggerEvent(deliveryOptions.disableDeliveryOptionsEvent);
          }
        }
      },

      /**
       * Get the new shipping method that should be saved.
       *
       * @param {String} methodCode - Method code to use to find a method.
       *
       * @returns {Object}
       */
      getNewShippingMethod: function(methodCode) {
        var newShippingMethod = [];
        var matchingShippingMethod = checkout.findRateByMethodCode(methodCode);

        if (matchingShippingMethod) {
          return matchingShippingMethod;
        } else {
          /**
           * If the method doesn't exist, loop through the allowed shipping methods and return the first one that
           *  matches.
           */
          window.MyParcelConfig.methods.forEach(function(method) {
            var foundMethod = checkout.findRateByMethodCode(method);

            if (foundMethod) {
              newShippingMethod.push(foundMethod);
            }
          });

          return newShippingMethod.length ? newShippingMethod[0] : null;
        }
      },
    };

    return deliveryOptions;
  }
);
