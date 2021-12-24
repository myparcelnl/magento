define(
  function() {
    'use strict';

    return function NewShipment(options) {
      var model = {

        /**
         * Initializes observable properties.
         *
         * @param {Object} options - Values carrier and packageType, supplied by new_shipment.phtml.
         * @returns {NewShipment} Chainable.
         */
        initialize: function(options) {
          var carriers = document.querySelectorAll('[name="mypa_carrier"]'),
            packageTypes = document.querySelectorAll('[name="mypa_package_type"]');
          this.mypa_carrier = options.carrier || 'postnl';
          this.mypa_package_type = options.packageType || 1;
          this.initializeSelectors(carriers);
          this.initializeSelectors(packageTypes);

          return this;
        },
        initializeSelectors: function(selectors) {
          var self = this,
            i,
            len,
            selector;
          for (i = 0, len = selectors.length; i < len; ++i) {
            selector = selectors[i];
            selector.addEventListener('change', function() {
              self.showForSelector(this);
            });
            if (this.mypa_carrier === selector.value) {
              selector.click();
            }
          }
        },
        showForSelector: function(radio) {
          var name = radio.name,
            value = radio.value,
            elements = document.querySelectorAll('[data-for_' + name + ']'),
            timeoutForLoadingSequence = 300,
            i,
            len,
            element,
            self = this;
          for (i = 0, len = elements.length; i < len; ++i) {
            element = elements[i];
            if (element.getAttribute('data-for_' + name) === value) {
              element.style.display = 'inherit';
              radio = element.querySelector('[type="radio"]');
              if (radio) {
                setTimeout(function(radio) {
                  self.clickActiveSelector(radio);
                }, timeoutForLoadingSequence, radio);
              }
            } else {
              element.style.display = 'none';
            }
          }
        },
        clickActiveSelector: function(radio) {
          if (radio.value === this.mypa_package_type.toString()) {
            radio.click();
          }
        },
      };

      return model.initialize(options);
    };
  }
);
