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
          this.leaveAllVisible = false;
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
            selector.addEventListener('click', function() {
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
            element;
          this[name] = value;
          for (i = 0, len = elements.length; i < len; ++i) {
            element = elements[i];
            if (element.getAttribute('data-for_' + name) === value) {
              if (element.parentNode.parentNode.hasAttribute('data-for_mypa_carrier')
                && this.mypa_carrier !== element.parentNode.parentNode.getAttribute('data-for_mypa_carrier')
              ) {
                this.toggleElement(element, false);
                continue;
              }
              this.toggleElement(element, true);
              radio = element.querySelector('[value="' + this.mypa_package_type + '"]');
              if (radio) {
                setTimeout(function(radio) {
                  radio.click();
                }, timeoutForLoadingSequence, radio);
              }
              continue;
            }
            this.toggleElement(element, false);
          }
        },
        toggleElement: function(element, visibility) {
          var inputs = element.getElementsByTagName('input'),
            selects = element.getElementsByTagName('select');
          element.style.display = visibility || this.leaveAllVisible ? 'inherit' : 'none';
          this.onEachElement(visibility ? this.setEnabled : this.setDisabled, inputs, selects);
        },
        onEachElement: function(callback) {
          var argumentCount,
            argumentInput,
            i,
            len;
          for (argumentCount in arguments) {
            argumentInput = arguments[argumentCount];
            if (callback === argumentInput) {
              continue;
            }
            for (i = 0, len = argumentInput.length; i < len; ++i) {
              callback(argumentInput[i]);
            }
          }
        },
        setEnabled: function(element) {
          element.removeAttribute('disabled');
        },
        setDisabled: function(element) {
          element.setAttribute('disabled', 'disabled');
        },
      };

      return model.initialize(options);
    };
  },
);
