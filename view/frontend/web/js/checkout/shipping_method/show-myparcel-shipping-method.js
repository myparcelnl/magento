define(
    [
        'uiComponent',
        'jquery',
        'Magento_Checkout/js/model/quote',
        'myparcelnl_options_template',
        'myparcelnl_lib_myparcel',
        'myparcelnl_lib_moment',
        'myparcelnl_lib_webcomponents'
    ],
    function(uiComponent, jQuery, quote, optionHtml) {
        'use strict';

        var  originalShippingRate, optionsContainer, timeoutLoadMyParcelOnce, myparcel;

        return {
            loadOptions: loadOptions,
            showOptions: showOptions,
            hideOptions: hideOptions
        };

        function loadOptions() {
            timeoutLoadMyParcelOnce = setTimeout(function(){
                clearTimeout(timeoutLoadMyParcelOnce);
                console.log(timeoutLoadMyParcelOnce);
                console.log('test234');
                _appendTemplate();
                _retrieveOptions();
                showOptions();
                _observeFields();
            }, 3000);
        }

        function showOptions() {
            //originalShippingRate.hide();
            optionsContainer.show();
        }

        function hideOptions() {
            originalShippingRate.show();
            //optionsContainer.hide();
        }

        function _observeFields() {
            jQuery("input[id^='s_method']").parent().on('change', function (event) {
                jQuery("input[name='delivery_options']").val('');
                myparcel.optionsHaveBeenModified();
            });
        }

        function _retrieveOptions() {
            window.mypa = {};
            _setParameters();
            myparcel = new MyParcel()
            myparcel.updatePage();
        }

        function _setParameters() {
            if (window.mypa == null) {
                window.mypa = {};
            }
            window.mypa.settings = {
                number: '55',
                street: 'Street name',
                postal_code: '2231je',
                price: {
                    morning: '&#8364; 12,00',
                    default: '&#8364; 12,00',
                    night: '&#8364; 12,00',
                    pickup: '&#8364; 12,00',
                    pickup_express: '&#8364; 12,00',
                    signed: '&#8364; 12,00',
                    only_recipient: '&#8364; 12,00',
                    combi_options: '&#8364; 12,00'
                },
                base_url: 'https://api.myparcel.nl/delivery_options',
                text:
                {
                    signed: 'Text show instead of default text',
                    only_recipient: 'Text show instead of default text'
                }
            };
        }

        function _appendTemplate() {
            originalShippingRate = jQuery('#label_carrier_flatrate_flatrate').parent().find('td');
            optionsContainer = originalShippingRate.parent().parent().prepend('<tr><td colspan="4" id="myparcel_options_td" style="display:none"></td></tr>').find('#myparcel_options_td');
            optionsContainer.html(optionHtml);
        }
    }
);