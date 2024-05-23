const STATUS_SUCCESS = 200;
const STATUS_ERROR = 400;
const EU_COUNTRIES = [
  'AT',
  'BE',
  'BG',
  'CY',
  'CZ',
  'DE',
  'DK',
  'EE',
  'ES',
  'FI',
  'FR',
  'GB',
  'GR',
  'HR',
  'HU',
  'IE',
  'IT',
  'LT',
  'LU',
  'LV',
  'MT',
  'NL',
  'PL',
  'PT',
  'RO',
  'SE',
  'SI',
  'SK',
];
define([
  'underscore',
  'ko',
  'mage/url',
  'Magento_Checkout/js/model/quote',
],
function(
  _,
  ko,
  mageUrl,
  quote
) {
  'use strict';

  var Model = {
    configuration: ko.observable(null),

    /**
     * Bind the observer to this model.
     */
    rates: null,

    /**
     * The allowed and present shipping methods for which the delivery options would be shown.
     */
    allowedShippingMethods: ko.observableArray([]),

    /**
     * Whether the delivery options will be shown or not.
     */
    hasDeliveryOptions: ko.observable(false),

    /**
     * The country code.
     */
    countryId: ko.observable(null),

    /**
     * Best package type.
     */
    bestPackageType: null,

    /**
     * Initialize by requesting the MyParcel settings configuration from Magento.
     */
    initialize: function() {
      Model.compute = ko.computed(function() {
        var configuration = Model.configuration();
        var rates = Model.rates();

        if (!configuration || !rates.length) {
          return false;
        }

        return {configuration: configuration, rates: rates};
      });

      Model.compute.subscribe(function(a) {
        if (!a) {
          return;
        }

        updateAllowedShippingMethods();
      });

      Model.compute.subscribe(_.debounce(Model.hideShippingMethods));
      Model.allowedShippingMethods.subscribe(_.debounce(updateHasDeliveryOptions));

      Model.countryId(quote.shippingAddress().countryId);
      doRequest(Model.getDeliveryOptionsConfig, {onSuccess: Model.onInitializeSuccess});

      function reloadConfig() {
        var shippingAddress = quote.shippingAddress();

        if (shippingAddress.countryId !== Model.countryId()) {
          doRequest(Model.getDeliveryOptionsConfig, {onSuccess: Model.onReFetchDeliveryOptionsConfig});
        }

        Model.countryId(shippingAddress.countryId);
      }
      quote.billingAddress.subscribe(reloadConfig);
      quote.shippingAddress.subscribe(reloadConfig);
    },

    onReFetchDeliveryOptionsConfig: function(response) {
      var configuration = response[0].data;
      Model.bestPackageType = configuration.config.packageType;
      Model.setDeliveryOptionsConfig(configuration);
    },

    /**
     * Fill in the configuration, hide shipping methods and update the allowedShippingMethods array.
     *
     * @param {Array} response - Response from request.
     */
    onInitializeSuccess: function(response) {
      Model.onReFetchDeliveryOptionsConfig(response);
      Model.hideShippingMethods();
    },

    /**
     * Search the rates for the given method code.
     *
     * @param {string} methodCode - Method code to search for.
     *
     * @returns {Object} - The found rate, if any.
     */
    findRateByMethodCode: function(methodCode) {
      return Model.rates().find(function(rate) {
        return rate.method_code === methodCode;
      });
    },

      /**
       * Search the rates for the given carrier code.
       *
       * @param {string} carrierCode - Carrier code to search for.
       *
       * @returns {Object} - The found rate, if any.
       */
    findOriginalRateByCarrierCode: function(carrierCode) {
      return Model.rates().find(function(rate) {
          if (-1 === rate.method_code.indexOf('myparcel')) {
              return rate.carrier_code === carrierCode;
          }
      });
    },

    /**
     * Hide the shipping methods the delivery options should replace.
     */
    hideShippingMethods: function() {
      var rowsToHide = [];

      Model.rates().forEach(function(rate) {
        const hasDeliveryOptions = Model.hasDeliveryOptions();
        const myParcelMethods = hasDeliveryOptions ? Model.configuration().methods || [] : [];
        const cell = document.getElementById('label_method_' + rate.method_code + '_' + rate.carrier_code) || null;

        if (!rate.available || !cell) {
          return;
        }

        const row = cell.parentElement;

        /**
         * Hide MyParcel-specific methods, and the parent methods delivery options are bound to
         */
        if (rate.method_code.indexOf('myparcel') !== -1) {
          rowsToHide.push(row);
        } else if (myParcelMethods.includes(rate.carrier_code)) {
          rowsToHide.push(row);
        }
      });

      rowsToHide.forEach(function(row) {
        row.style.display = 'none';
      });
    },

    /**
     * Execute the delivery_options request to retrieve the settings object.
     *
     * @returns {XMLHttpRequest}
     */
    getDeliveryOptionsConfig: function() {
      return sendRequest(
        'rest/V1/delivery_options/config',
        'POST',
        JSON.stringify({shippingAddress: [quote.shippingAddress()]})
      );
    },

    /**
     * This method reads the countryId from the checkout form select list country_id, this is Magento standard.
     * There may be checkout plugins without country_id, which we do not support fully
     *
     * @param {String} carrier
     * @returns {XMLHttpRequest}
     */
    calculatePackageType: function(carrier) {
      var list = document.querySelector('[name="country_id"]');
      function isVisible(el) {
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
      }
      var countryId = (list && isVisible(list)) ? list.options[list.selectedIndex].value : Model.countryId();
      return sendRequest(
        'rest/V1/package_type',
        'GET',
        {
          carrier: carrier,
          countryCode: countryId,
        }
      );
    },

    /**
     * Execute the shipping_methods request to convert delivery options to a shipping method id.
     *
     * @param {Object} deliveryOptions - Delivery options data.
     * @param {Object} handlers - Object with handlers to run on different outcomes of the request.
     */
    convertDeliveryOptionsToShippingMethod: function(deliveryOptions, handlers) {
      doRequest(
        function() {
          return sendRequest(
            'rest/V1/shipping_methods',
            'POST',
            JSON.stringify({deliveryOptions: [deliveryOptions]})
          );
        }, handlers
      );
    },

    /**
     * @param {Object} data - Update MyParcelConfig.
     */
    setDeliveryOptionsConfig: function(data) {
      data.config.packageType = Model.bestPackageType;
      window.MyParcelConfig = data;
      Model.configuration(data);
    },

    /**
     * @param {String} carrier
     */
    updatePackageType: function(carrier) {
      doRequest(function() {
        return Model.calculatePackageType(carrier);
      },
      {
        onSuccess: function(response) {
          Model.bestPackageType = response;
        },
      });
    },
  };

  return Model;

  function updateAllowedShippingMethods() {
    /**
     * Filter the allowed shipping methods by checking if they are actually present in the checkout. If not they will
     *  be left out.
     */
    Model.allowedShippingMethods(Model.configuration().methods.filter(function(carrierCode) {
      return !!Model.findOriginalRateByCarrierCode(carrierCode);
    }));
  }

  function updateHasDeliveryOptions() {
    let isAllowed = false;
    const shippingCountry = quote.shippingAddress().countryId;

    Model.allowedShippingMethods().forEach(function(carrierCode) {
      const rate = Model.findOriginalRateByCarrierCode(carrierCode);
      if (rate && rate.available) {
        isAllowed = true;
      }
    });

    Model.hasDeliveryOptions(isAllowed);
    Model.hideShippingMethods();
  }

  /**
   * Request function. Executes a request and given handlers.
   *
   * @param {Function} request - The request to execute.
   * @param {Object} handlers - Object with handlers to run on different outcomes of the request.
   * @property {Function} handlers.onSuccess - Function to run on Success handler.
   * @property {Function} handlers.onError - Function to run on Error handler.
   * @property {Function} handlers.always - Function to always run.
   */
  function doRequest(request, handlers) {
    /**
     * Execute a given handler by name if it exists in handlers.
     *
     * @param {String} handlerName - Name of the handler to check for.
     * @param {*?} params - Parameters to pass to the handler.
     * @returns {*}
     */
    handlers.doHandler = function(handlerName, params) {
      if (handlers.hasOwnProperty(handlerName) && typeof handlers[handlerName] === 'function') {
        return handlers[handlerName](params);
      }
    };

    request().onload = function() {
      var response = JSON.parse(this.response);

      if (this.status >= STATUS_SUCCESS && this.status < STATUS_ERROR) {
        handlers.doHandler('onSuccess', response);
      } else {
        handlers.doHandler('onError', response);
      }

      handlers.doHandler('always', response);
    };
  }

  /**
   * Send a request to given endpoint.
   *
   * @param {String} endpoint - Endpoint to use.
   * @param {String} [method='GET'] - Request method.
   * @param {Object} [options={}] - Request body or params.
   *
   * @returns {XMLHttpRequest}
   */
  function sendRequest(endpoint, method, options) {
    var url = mageUrl.build(endpoint);
    var request = new XMLHttpRequest();
    var query = [];

    method = method || 'GET';
    options = options || {};

    if (method === 'GET') {
      for (var key in options) {
        query.push(key + '=' + encodeURIComponent(options[key]));
      }
    }

    if (query.length) {
      url += '?' + query.join('&');
    }

    request.open(method, url, true);
    request.setRequestHeader('Content-Type', 'application/json');
    request.send(options);

    return request;
  }
});
