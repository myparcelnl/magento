define(
    [
        'uiComponent',
        'jquery',
        'Magento_Checkout/js/model/quote',
        'myparcelnl_options_template',
        'myparcelnl_options_css',
        'myparcelnl_lib_myparcel',
        'myparcelnl_lib_moment',
        'myparcelnl_lib_webcomponents'
    ],
    function(uiComponent, jQuery, quote, optionsHtml, optionsCss) {
        'use strict';

        var  originalShippingRate, optionsContainer, isLoaded, myparcel, delivery_options_input, myparcel_method;

        return {
            loadOptions: loadOptions,
            showOptions: showOptions,
            hideOptions: hideOptions
        };

        function loadOptions() {
            console.log(optionsCss);

            if (typeof window.mypa === 'undefined') {
                window.mypa = {isLoaded: false};
            }
            if (window.mypa.isLoaded === false) {
                window.mypa.isLoaded = true;
                isLoaded = setTimeout(function(){
                    window.mypa.isLoaded = false;
                    clearTimeout(isLoaded);
                    _appendTemplate();
                    _retrieveOptions();
                    showOptions();
                    _observeFields();
                }, 50);
            }
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
            delivery_options_input = jQuery("input[name='delivery_options']");

            /** todo add standard method to myparcel_method */
            myparcel_method = "input[id^='s_method_mypa_']";

            /*jQuery("input[id^='s_method']").parent().on('change', function (event) {
                console.log(jQuery(myparcel_method + ':checked'));
                if (jQuery(myparcel_method + ':checked').length === 0) {
                    console.log('notMyParcel');
                    delivery_options_input.val('');
                    myparcel.optionsHaveBeenModified();
                }
            });*/

            delivery_options_input.on('change', function (event) {
                _checkShippingMethod();
            });
        }

        function _retrieveOptions() {
            _setParameters();
            myparcel = new MyParcel();
            myparcel.updatePage();
        }

        function _setParameters() {
            window.mypa.settings = {
                deliverydays_window: 10,
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
                    /*signed: 'Text show instead of default text',
                    only_recipient: 'Text show instead of default text'*/
                }
            };
        }

        function _appendTemplate() {
            var baseColor = '#848484';
            var selectColor = '#001fff';
            optionsCss = optionsCss.replace(/_base_color_/g, baseColor).replace(/_select_color_/g, selectColor);
            optionsHtml = optionsHtml.replace('<to_replace/>', optionsCss);

            originalShippingRate = jQuery('#label_carrier_flatrate_flatrate').parent().find('td');
            optionsContainer = originalShippingRate.parent().parent().prepend('<tr><td colspan="4" id="myparcel_td" style="display:none"></td></tr>').find('#myparcel_td');
            optionsContainer.html(optionsHtml);
        }

        function _checkShippingMethod() {
            var inputValue, json, type;

            inputValue = delivery_options_input.val();
            if (inputValue === '') {
                return;
            }

            json = jQuery.parseJSON(inputValue);

            if (typeof json.time[0].price_comment !== 'undefined') {
                type = json.time[0].price_comment;
            } else {
                type = json.price_comment;
            }

            switch (type) {
                case "morning":
                    if (json.options.signature) {
                        _checkMethod('#s_method_mypa_morning_signature');
                    } else {
                        _checkMethod('#s_method_mypa_morning');
                    }
                    myparcel.showDays();
                    break;
                case "standard":
                    console.log(json.options);
                    if (json.options.signature && json.options.only_recipient) {
                        _checkMethod('#s_method_mypa_signature_only_recip');
                    } else {
                        if (json.options.signature) {
                            _checkMethod('#s_method_mypa_signature');
                        } else if (json.options.only_recipient) {
                            _checkMethod('#s_method_mypa_only_recipient');
                        } else {
                            _checkMethod('#s_method_flatrate_flatrate');
                        }
                    }
                    myparcel.showDays();
                    break;
                case "night":
                    if (json.options.signature) {
                        _checkMethod('#s_method_mypa_evening_signature');
                    } else {
                        _checkMethod('#s_method_mypa_evening');
                    }
                    myparcel.showDays();
                    break;
                case "retail":
                    _checkMethod('#s_method_mypa_pickup');
                    myparcel.hideDays();
                    break;
                case "retailexpress":
                    _checkMethod('#s_method_mypa_pickup_express');
                    myparcel.hideDays();
                    break;
                case "mailbox":
                    _checkMethod('#s_method_mypa_mailbox');
                    myparcel.hideDays();
                    break;
            }
        }

        function _checkMethod(selector) {
            jQuery("input[id^='s_method']").prop("checked", false).change();
            jQuery(selector).prop("checked", true).change().trigger('click');
        }
    }
);