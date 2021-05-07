define(
  [
    'underscore',
    'ko',
    'Magento_Checkout/js/model/shipping-rate-registry',
    'Magento_Checkout/js/model/quote',
    'MyParcelNL_Magento/js/model/checkout',
    'MyParcelNL_Magento/js/polyfill/array_prototype_find',
    'MyParcelNL_Magento/js/vendor/myparcel',
    'MyParcelNL_Magento/js/vendor/polyfill-custom-event',
    'MyParcelNL_Magento/js/vendor/object-path',
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
    objectPath,
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
      updateConfigEvent: 'myparcel_update_config',
      updateDeliveryOptionsEvent: 'myparcel_update_delivery_options',

      updatedDeliveryOptionsEvent: 'myparcel_updated_delivery_options',
      updatedAddressEvent: 'myparcel_updated_address',

      disableDelivery: 'myparcel-delivery-options__delivery--deliver',
      disablePickup: 'myparcel-delivery-options__delivery--pickup',

      isUsingMyParcelMethod: true,

      /**
       * The selector of the field we use to get the delivery options data into the order.
       *
       * @type {String}
       */
      hiddenDataInput: '[name="myparcel_delivery_options"]',

      methodCodeStandardDelivery: 'myparcelnl_magento_postnl_settings/delivery',

      /**
       * Maps shipping method codes to prices in the delivery options config.
       */
      methodCodeDeliveryOptionsConfigMap: {
        'myparcelnl_magento_postnl_settings/delivery': 'config.carrierSettings.postnl.priceStandardDelivery',
        'myparcelnl_magento_postnl_settings/mailbox': 'config.carrierSettings.postnl.pricePackageTypeMailbox',
        'myparcelnl_magento_postnl_settings/digital_stamp': 'config.carrierSettings.postnl.pricePackageTypeMailbox',
        'myparcelnl_magento_postnl_settings/morning/only_recipient': 'config.carrierSettings.postnl.priceMorningDelivery',
        'myparcelnl_magento_postnl_settings/evening/only_recipient': 'config.carrierSettings.postnl.priceEveningDelivery',
        'myparcelnl_magento_postnl_settings/pickup': 'config.carrierSettings.postnl.pricePickup',
        'myparcelnl_magento_postnl_settings/morning/only_recipient/signature': 'config.carrierSettings.postnl.priceMorningSignature',
        'myparcelnl_magento_postnl_settings/evening/only_recipient/signature': 'config.carrierSettings.postnl.priceEveningSignature',
        'myparcelnl_magento_postnl_settings/delivery/only_recipient/signature': 'config.carrierSettings.postnl.priceSignatureAndOnlyRecipient',
      },

      /**
       * Maps shipping method codes to prices in the delivery options config.
       */
      methodCodeShipmentOptionsConfigMap: {
        'myparcelnl_magento_postnl_settings/delivery/signature': 'config.carrierSettings.postnl.priceSignature',
        'myparcelnl_magento_postnl_settings/delivery/only_recipient': 'config.carrierSettings.postnl.priceOnlyRecipient',
      },

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
        checkout.hideShippingMethods();
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
        checkout.configuration.subscribe(deliveryOptions.updateConfig);
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
        var result = deliveryOptions.splitStreetRegex.exec(address);
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
        if (!deliveryOptions.isUsingMyParcelMethod) {
          return;
        }

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
            if (!response.length) {
              return;
            }

            quote.shippingMethod(deliveryOptions.getNewShippingMethod(response[0].element_id));
          },
        });

        deliveryOptions.disabledDeliveryPickupRadio();
      },

      /**
       * Note: If you only have one option, so either "delivery" or "pickup", the option will appear disabled.
       * Until there's a built in solution, there's the following workaround.
       */
      disabledDeliveryPickupRadio: function() {
        var delivery = document.getElementById(deliveryOptions.disableDelivery);
        var pickup = document.getElementById(deliveryOptions.disablePickup);

        if (delivery) {
          delivery.disabled = false;
        }

        if (pickup) {
          pickup.disabled = false;
        }
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

        deliveryOptions.updatePricesInDeliveryOptions(selectedShippingMethod);

        if (JSON.stringify(deliveryOptions.shippingMethod) !== JSON.stringify(newShippingMethod)) {
          deliveryOptions.shippingMethod = newShippingMethod;

          if (!isMyParcelMethod && !methodEnabled) {
            deliveryOptions.triggerEvent(deliveryOptions.disableDeliveryOptionsEvent);
            deliveryOptions.isUsingMyParcelMethod = false;
          } else {
            deliveryOptions.isUsingMyParcelMethod = true;
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

      updatePricesInDeliveryOptions: function(selectedShippingMethod) {
        var isShipmentOption = deliveryOptions.methodCodeShipmentOptionsConfigMap.hasOwnProperty(selectedShippingMethod.method_code);
        var priceOption = deliveryOptions.methodCodeDeliveryOptionsConfigMap[selectedShippingMethod.method_code];
        var addBasePrice = false;

        if (isShipmentOption) {
          priceOption = deliveryOptions.methodCodeShipmentOptionsConfigMap[selectedShippingMethod.method_code];
          addBasePrice = true;
        }

        deliveryOptions.priceDeliveryOptions(selectedShippingMethod, priceOption, addBasePrice);
      },

      /**
       * @param {Object} shippingMethod
       * @param {String} priceOption
       * @param {Boolean} addBasePrice
       */
      priceDeliveryOptions: function(shippingMethod, priceOption, addBasePrice) {
        var hasKey = objectPath.has(window.MyParcelConfig, priceOption);

        if (!hasKey) {
          // eslint-disable-next-line no-console
          console.error('key does not exist');
          return;
        }

        var existingPrice = objectPath.get(window.MyParcelConfig, priceOption, null);
        var shippingMethodPrice = shippingMethod.price_incl_tax;
        var isMyParcelMethod = shippingMethod.method_code.indexOf('myparcel') > -1;

        if (addBasePrice) {
          var baseShippingMethod = checkout.findRateByMethodCode(deliveryOptions.methodCodeStandardDelivery);
          shippingMethodPrice -= baseShippingMethod.price_incl_tax;
          shippingMethodPrice = deliveryOptions.roundNumber(shippingMethodPrice, 2);
        }

        if (existingPrice && existingPrice !== shippingMethodPrice && isMyParcelMethod) {
          objectPath.set(window.MyParcelConfig, priceOption, shippingMethodPrice);

          deliveryOptions.triggerEvent(deliveryOptions.updateConfigEvent);
        }
      },

      /**
       * For use when magic decimals appear...
       *
       * @param {Number} number
       * @param {Number} decimals
       * @returns {Number}
       *
       * @see https://stackoverflow.com/a/10474209
       */
      roundNumber: function(number, decimals) {
        var newNumber = Number(String(number)).toFixed(decimals);
        return parseFloat(newNumber);
      },

      updateConfig: function() {
        deliveryOptions.triggerEvent(deliveryOptions.updateConfigEvent);
      },
    };

    return deliveryOptions;
  }
);
