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
          this.mypa_package_type = options.packageType || 'package';
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
              radio = element.querySelector('[type="radio"]');
              this.toggleElement(element, true);
              if (radio) {
                setTimeout(function(radio) {
                  self.clickActiveSelector(radio);
                }, timeoutForLoadingSequence, radio);
              }
            } else {
              this.toggleElement(element, false);
            }
          }
        },
        clickActiveSelector: function(radio) {
          if (radio.value === this.mypa_package_type.toString()) {
            radio.click();
          }
        },
        toggleElement: function(element, visibility) {
          var inputs = element.getElementsByTagName('input'),
            selects = element.getElementsByTagName('select');
          element.style.display = visibility ? 'inherit' : 'none';
          if (visibility) {
            this.setEnabled(inputs, selects);
          } else {
            this.setDisabled(inputs, selects);
          }
        },
        // todo the shipment options form elements must be disabled for non-active carriers also directly after loading
        // todo refactor setEnabled and setDisabled because of duplicate code
        setEnabled: function() {
          var type, i, len, elements;
          for (type in arguments) {
            elements = arguments[type];
            for (i = 0, len = elements.length; i < len; ++i) {
              elements[i].removeAttribute('disabled');
            }
          }
        },
        setDisabled: function() {
          var type, i, len, elements;
          for (type in arguments) {
            elements = arguments[type];
            for (i = 0, len = elements.length; i < len; ++i) {
              elements[i].setAttribute('disabled', 'disabled');
            }
          }
        },
      };

      return model.initialize(options);
    };
  },
);
