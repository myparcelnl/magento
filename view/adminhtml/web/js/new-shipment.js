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
          var carriers = document.querySelectorAll('[name="mypa_carrier"]');
          var packageTypes = document.querySelectorAll('[name="mypa_package_type"]');
          var toggleMyParcel = document.getElementById('mypa_create_from_observer');
          this.mypa_carrier = options.carrier || 'postnl';
          this.mypa_package_type = options.packageType || 'package';
          this.leaveAllVisible = false;
          this.initializeSelectors(carriers);
          this.initializeSelectors(packageTypes);
          this.initializeToggle(toggleMyParcel, 'js--mypa-options');

          return this;
        },
        initializeToggle: function(checkbox, classNameWillBeToggled) {
          var self = this;
          checkbox.addEventListener('click', function() {
            var elements = document.getElementsByClassName(classNameWillBeToggled);
            var i;
            var len;
            for (i = 0, len = elements.length; i < len; ++i) {
              self.toggleElement(elements[i], this.checked);
            }
          });
        },
        initializeSelectors: function(selectors) {
          var self = this;
          var i;
          var len;
          var selector;
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
          var name = radio.name;
          var value = radio.value;
          var elements = document.querySelectorAll('[data-for_' + name + ']');
          var timeoutForLoadingSequence = 300;
          var i;
          var len;
          var element;
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
          var inputs = element.getElementsByTagName('input');
          var selects = element.getElementsByTagName('select');
          element.style.display = visibility || this.leaveAllVisible ? 'inherit' : 'none';
          this.onEachElement(visibility ? this.setEnabled : this.setDisabled, inputs, selects);
        },
        onEachElement: function(callback) {
          var argumentCount;
          var argumentInput;
          var i;
          var len;
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
  }
);
