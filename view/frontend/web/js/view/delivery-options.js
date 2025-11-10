define(
  [
    'underscore',
    'ko',
    'Magento_Checkout/js/action/select-shipping-method',
    'Magento_Checkout/js/model/quote',
    'MyParcelNL_Magento/js/model/checkout',
    'myparcelDeliveryOptions',
    'leaflet',
    'jquery'
  ],
  function(
    _,
    ko,
    selectShippingMethodAction,
    quote,
    checkout,
    myparcel, // for rendering the delivery options
    leaflet, // required by the delivery options module
    $
  ) {
    'use strict';

    const deliveryOptions = {
      rendered: ko.observable(false),

      splitStreetRegex: /(.*?)\s?(\d{1,5})[/\s-]{0,2}([A-z]\d{1,3}|-\d{1,4}|\d{2}\w{1,2}|[A-z][A-z\s]{0,3})?$/,

      disableDeliveryOptionsEvent: 'myparcel_disable_delivery_options',
      hideDeliveryOptionsEvent: 'myparcel_hide_delivery_options',
      renderDeliveryOptionsEvent: 'myparcel_render_delivery_options',
      showDeliveryOptionsEvent: 'myparcel_show_delivery_options',
      unselectDeliveryOptionsEvent: 'myparcel_unselect_delivery_options',
      updateConfigEvent: 'myparcel_update_config',
      updateDeliveryOptionsEvent: 'myparcel_update_delivery_options',

      updatedDeliveryOptionsEvent: 'myparcel_updated_delivery_options',
      updatedAddressEvent: 'myparcel_updated_address',

      disableDelivery: 'myparcel-delivery-options__delivery--deliver',
      disablePickup: 'myparcel-delivery-options__delivery--pickup',

      localStorageKey: 'myparcel-shipping-method',

      isUsingMyParcelMethod: true,
      deliveryOptionsAreVisible: false,

      /**
       * The selector of the field we use to get the delivery options data into the order.
       *
       * @type {string}
       */
      hiddenDataInput: '[name="myparcel_delivery_options"]',

      /**
       * Initialize the script. Render the delivery options div, request the plugin settings, then initialize listeners.
       */
      initialize: function() {
        window.MyParcelConfig.address = deliveryOptions.getAddress(quote.shippingAddress());
        checkout.hideShippingMethods();
        deliveryOptions.setToRenderWhenVisible();
        deliveryOptions.addListeners();

        deliveryOptions.rendered.subscribe(function(bool) {
          if (bool) {
            deliveryOptions.updateAddress();
          }
        });
      },

      setToRenderWhenVisible: function() {
        const shippingMethodDiv = document.getElementById('checkout-shipping-method-load');
        /**
         * Sometimes the shipping method div doesn't exist yet. Retry in 151ms if it happens.
         */
        if (!shippingMethodDiv) {
          setTimeout(function() {
            deliveryOptions.setToRenderWhenVisible();
          }, 151);
          return;
        }

        if (!('IntersectionObserver' in window) ||
          !('IntersectionObserverEntry' in window) ||
          !('intersectionRatio' in window.IntersectionObserverEntry.prototype)
        ) {
          deliveryOptions.render();
          return;
        }

        const observer = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.intersectionRatio === 0 || deliveryOptions.deliveryOptions || this.deliveryOptionsAreVisible) {
              return;
            }
            deliveryOptions.render();
            this.deliveryOptionsAreVisible = true;
          }, {
            root: null,
            rootMargin: '0px',
            threshold: .1
          });
        });
        observer.observe(shippingMethodDiv);
      },

      destroy: function() {
        document.querySelector(deliveryOptions.hiddenDataInput).value = '';
        deliveryOptions.triggerEvent(deliveryOptions.hideDeliveryOptionsEvent);
      },

      render: function() {
        const deliveryOptionsDiv = document.getElementById('myparcel-delivery-options'),
          shippingMethodDiv = document.getElementById('checkout-shipping-method-load');

        if (deliveryOptionsDiv) {
          deliveryOptions.triggerEvent(deliveryOptions.updateDeliveryOptionsEvent);
        } else {
          const newDeliveryOptionsDiv = document.createElement('div');
          newDeliveryOptionsDiv.setAttribute('id', 'myparcel-delivery-options');
          shippingMethodDiv.insertAdjacentElement('afterbegin', newDeliveryOptionsDiv);
          requestAnimationFrame(function() { // wait for the element to actually be added to the DOM
            deliveryOptions.triggerEvent(deliveryOptions.renderDeliveryOptionsEvent)
          });
        }

        deliveryOptions.rendered(true);
      },

      /**
       * Add event listeners to shipping methods and address as well as the delivery options module.
       */
      addListeners: function() {
        checkout.configuration.subscribe(deliveryOptions.updateConfig);
        quote.shippingAddress.subscribe(_.debounce(deliveryOptions.updateAddress));
        quote.shippingMethod.subscribe(_.debounce(deliveryOptions.onShippingMethodUpdate));

        document.addEventListener(
          deliveryOptions.updatedDeliveryOptionsEvent,
          deliveryOptions.onUpdatedDeliveryOptions
        );
      },

      /**
       * Run the split street regex on the given full address to extract the house number and return it.
       *
       * @param {string} address - Full address.
       *
       * @returns {integer|null} - The house number, if found. Otherwise null.
       */
      getHouseNumber: function(address) {
        const result = deliveryOptions.splitStreetRegex.exec(address),
          numberIndex = 2;
        return result ? parseInt(result[numberIndex]) : null;
      },

      /**
       * Trigger an event on the document body.
       *
       * @param {string} identifier - Name of the event.
       */
      triggerEvent: function(identifier) {
        document.body.dispatchEvent(new Event(identifier, {bubbles: true, cancelable: false}));
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

        const newAddress = deliveryOptions.getAddress(address || quote.shippingAddress());
        if (_.isEqual(newAddress, window.MyParcelConfig.address)) {
          return;
        }

        window.MyParcelConfig.address = newAddress;

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
          street: address.street ? [address.street[0], address.street[1]].join(' ').trim() : ''
        };
      },

      /**
       * Triggered when the delivery options have been updated. Put the received data in the created data input. Then
       * do the request that tells us which shipping method needs to be selected.
       *
       * @param {CustomEvent} event - The event that was sent.
       */
      onUpdatedDeliveryOptions: function(event) {
        const element = document.querySelector('.checkout-shipping-method'),
          displayStyle = window.getComputedStyle(element, null).display,
          detail = event.detail;

        if ('none' === displayStyle) {
          return;
        }

        deliveryOptions.deliveryOptions = detail;
        document.querySelector(deliveryOptions.hiddenDataInput).value = JSON.stringify(detail);

        /**
         * If the delivery options were emptied, don't request a new shipping method.
         */
        if (JSON.stringify(deliveryOptions.deliveryOptions) === '{}') {
          return;
        }

        deliveryOptions.setShippingMethod(detail);
      },

      /**
       * @param options
       */
      setShippingMethod: function(options) {
        $('body').trigger('processStart');
        if (options) {
          options.packageType = checkout.bestPackageType;
        }
        checkout.convertDeliveryOptionsToShippingMethod(options, {
          onSuccess: function(response) {
            $('body').trigger('processStop');
            if (!response.length) {
              return;
            }
            // select MyParcel shipping method
            const row = checkout.rowElement();
            row && row.click();
            const shippingMethod = response[0];
            selectShippingMethodAction(shippingMethod);
            /**
             * The shipping method title on the quote is not updated on the fly by Magento2, unlike the totals.
             * So we remember the shipping method ourselves for retrieval in the summary:
             * Magento_Checkout/js/view/summary/shipping -> MyParcelNL_Magento/js/view/shipping-summary
             */
            localStorage.setItem(deliveryOptions.localStorageKey, JSON.stringify(shippingMethod));
          },
          onError: function(response) {
            $('body').trigger('processStop');
            console.error(response.message || 'An error occurred in the MyParcel plugin.');
          },
        });
      },

      /**
       * Change the shipping method and disable the delivery options if needed.
       *
       * @param {Object} selectedShippingMethod - The shipping method that was selected.
       */
      onShippingMethodUpdate: function(selectedShippingMethod) {
        const newShippingMethod = selectedShippingMethod || {},
          available = newShippingMethod.available || false,
          carrierCode = newShippingMethod.carrier_code || '',
          myparcelCarrierCode = checkout.carrierCode;

        if (!checkout.hasDeliveryOptions() || !available) {
          return;
        }

        if (carrierCode !== myparcelCarrierCode) {
          deliveryOptions.triggerEvent(deliveryOptions.unselectDeliveryOptionsEvent);
          deliveryOptions.isUsingMyParcelMethod = false;
          return;
        }

        deliveryOptions.shippingMethod = newShippingMethod;
        deliveryOptions.isUsingMyParcelMethod = true;
      },

      updateConfig: function() {
        if (!window.MyParcelConfig.hasOwnProperty('address')) {
          window.MyParcelConfig.address = deliveryOptions.getAddress(quote.shippingAddress());
        }
        deliveryOptions.triggerEvent(deliveryOptions.updateConfigEvent);
      },
    };

    return deliveryOptions;
  }
);
