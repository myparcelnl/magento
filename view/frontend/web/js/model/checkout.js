var STATUS_SUCCESS = 200;
var STATUS_ERROR = 400;

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

      doRequest(Model.getMagentoSettings, {onSuccess: Model.onInitializeSuccess});
    },

    /**
     * Fill in the configuration, hide shipping methods and update the allowedShippingMethods array.
     *
     * @param {Array} response - Response from request.
     */
    onInitializeSuccess: function(response) {
      window.MyParcelConfig = response[0].data;
      Model.configuration(response[0].data);
      Model.hideShippingMethods();
    },

    /**
     * Search the rates for the given method code.
     *
     * @param {String} methodCode - Method code to search for.
     *
     * @returns {Object} - The found rate, if any.
     */
    findRateByMethodCode: function(methodCode) {
      return Model.rates().find(function(rate) {
        return rate.method_code === methodCode;
      });
    },

    /**
     * Hide the shipping methods the delivery options should replace.
     */
    hideShippingMethods: function() {
      var rowsToHide = [];

      Model.rates().forEach(function(rate) {
        var row = Model.getShippingMethodRow(rate.method_code);

        if (!rate.available) {
          return;
        }

        if (rate.method_code.indexOf('myparcel') > -1 && row) {
          rowsToHide.push(Model.getShippingMethodRow(rate.method_code));
        }
      });

      /**
       * Only hide the allowed shipping method if the delivery options are present.
       */
      if (Model.hasDeliveryOptions()) {
        Model.allowedShippingMethods().forEach(function(shippingMethod) {
          var row = Model.getShippingMethodRow(shippingMethod);

          if (row) {
            rowsToHide.push(row);
          }
        });
      }

      rowsToHide.forEach(function(row) {
        row.style.display = 'none';
      });
    },

    /**
     * Get a shipping method row by finding the column with a matching method_code and grabbing its parent.
     *
     * @param {String} shippingMethod - Shipping method to get the row of.
     *
     * @returns {Element}
     */
    getShippingMethodRow: function(shippingMethod) {
      var classSelector = '.col.col-method[id*="' + shippingMethod + '"]';
      var column = document.querySelector(classSelector);

      /**
       * Return column if it is undefined or else there would be an error trying to get the parentElement.
       */
      return column ? column.parentElement : column;
    },

    /**
     * Execute the delivery_options request to retrieve the settings object.
     *
     * @returns {XMLHttpRequest}
     */
    getMagentoSettings: function() {
      return sendRequest('rest/V1/delivery_options/get');
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
  };

  return Model;

  function updateAllowedShippingMethods() {
    /**
     * Filter the allowed shipping methods by checking if they are actually present in the checkout. If not they will
     *  be left out.
     */
    Model.allowedShippingMethods(Model.configuration().methods.filter(function(rate) {
      return !!Model.findRateByMethodCode(rate);
    }));
  }

  function updateHasDeliveryOptions() {
    var isAllowed = false;

    Model.allowedShippingMethods().forEach(function(methodCode) {
      var rate = Model.findRateByMethodCode(methodCode);

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
   * @param {function} request - The request to execute.
   * @param {Object} handlers - Object with handlers to run on different outcomes of the request.
   * @property {function} handlers.onSuccess - Function to run on Success handler.
   * @property {function} handlers.onError - Function to run on Error handler.
   * @property {function} handlers.always - Function to always run.
   */
  function doRequest(request, handlers) {
    /**
     * Execute a given handler by name if it exists in handlers.
     *
     * @param {string} handlerName - Name of the handler to check for.
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
   * @param {String} [body={}] - Request body.
   *
   * @returns {XMLHttpRequest}
   */
  function sendRequest(endpoint, method, body) {
    var url = mageUrl.build(endpoint);
    var request = new XMLHttpRequest();

    method = method || 'GET';
    body = body || {};

    request.open(method, url, true);
    request.setRequestHeader('Content-Type', 'application/json');
    request.send(body);

    return request;
  }
});
