define(
    [
        'mage/url',
        'uiComponent',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        'jquery',
        'text!MyParcelNL_Magento/template/checkout/options.html',
        'text!MyParcelNL_Magento/css/checkout/options-dynamic.min.css',
        'MyParcelNL_Magento/js/lib/myparcel',
        'Magento_Checkout/js/action/set-shipping-information',
        'uiRegistry'
    ],
    function(mageUrl, uiComponent, quote, customer, checkoutData,jQuery, optionsHtml, cssDynamic, moment, setShippingInformationAction, registry) {
        'use strict';

        var originalShippingRate, optionsContainer, isLoading, myparcel, delivery_options_input, myparcel_method_alias, myparcel_method_element, isLoadingAddress;

        return {
            loadOptions: loadOptions,
            showOptions: showOptions,
            hideOptions: hideOptions
        };

        function loadOptions() {
            if (typeof window.mypa === 'undefined') {
                window.mypa = {isLoading: false, fn: {}};
            }
            window.mypa.fn.hideOptions = hideOptions;
            window.mypa.moment = moment;
            window.mypa.setShippingInformationAction = setShippingInformationAction;

            if (window.mypa.isLoading === false) {
                _hideRadios();
                window.mypa.isLoading = true;
                isLoading = setTimeout(function(){
                    clearTimeout(isLoading);
                    _hideRadios();

                    jQuery.ajax({
                        url: mageUrl.build('rest/V1/delivery_settings/get'),
                        type: "GET",
                        dataType: 'json',
                        showLoader: true
                    }).done(function (response) {
                        window.mypa.data = response[0].data;
                        init();
                        window.mypa.isLoading = false;
                    });

                }, 50);
            }
        }

        function init() {
            if ((myparcel_method_alias = window.mypa.data.general.parent_carrier) === null) {
                return void 0;
            }

            myparcel_method_element = "input[id^='s_method_" + myparcel_method_alias + "_']";
            checkAddress();
        }

        function checkAddress() {
            isLoadingAddress = setTimeout(function(){
                clearTimeout(isLoadingAddress);
                _setAddress();
                _hideRadios();

                if (checkOnlyShowRadioButton('digital_stamp')) {
                    showDeliveryRadio('digital_stamp');
                } else if (checkOnlyShowRadioButton('mailbox')) {
                    showDeliveryRadio('mailbox');
                } else if (_getCcIsLocal() && _getHouseNumber() !== null) {
                    _appendTemplate();
                    _setParameters();
                    showOptions();
                } else {
                    jQuery(myparcel_method_element + ":first").parent().parent().show();
                    hideOptions();
                }

                _observeFields();
            }, 1000);
        }

        function checkOnlyShowRadioButton(extraOption) {
            if (_getCcIsLocal() === false || window.mypa.data[extraOption].active === false) {
                return false
            }

            return true;
        }

        function showDeliveryRadio(extraOption, typeTitle) {

            typeTitle = (typeof typeTitle !== 'undefined') ? typeTitle : '';
            jQuery("td[id^='label_carrier_" + window.mypa.data.general.parent_method + "']").parent().hide();
            jQuery("td[id^='label_carrier_"+ extraOption +"']").parent().show();

            if (extraOption === "digital_stamp"){
                typeTitle = "Digital stamp";
            }

            if(extraOption === "mailbox"){
                typeTitle = "Mailbox";
            }
            jQuery("td[id^='label_carrier_"+ extraOption +"_" + window.mypa.data.general.parent_method + "']").html(typeTitle);
            jQuery("input[name='delivery_options']").val('{"time":[{"price_comment":"'+ extraOption +'"}]}');
        }

        function _setAddress() {

            var fieldsetName = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.';
            var address = [];

            if (customer.isLoggedIn() &&
                typeof quote !== 'undefined' &&
                typeof quote.shippingAddress !== 'undefined' &&
                typeof quote.shippingAddress._latestValue !== 'undefined' &&
                typeof quote.shippingAddress._latestValue.street !== 'undefined' &&
                typeof quote.shippingAddress._latestValue.street[0] !== 'undefined'
            ) {
                address = getLoggedInAddress();
            } else {
                address = getNoLoggedInAddress(fieldsetName);
            }

            window.mypa.address             = [];
            window.mypa.address.street0     = address.data.street0.replace(/[<>=]/g, '');
            window.mypa.address.street1     = address.data.street1.replace(/[<>=]/g, '');
            window.mypa.address.street2     = address.data.street2.replace(/[<>=]/g, '');
            window.mypa.address.cc          = address.data.country.replace(/[<>=]/g, '');
            window.mypa.address.postcode    = address.data.postcode.replace(/[\s<>=]/g, '');
            window.mypa.address.city        = address.data.city.replace(/[<>=]/g, '');
        }

        function getLoggedInAddress() {
            var street0     = quote.shippingAddress._latestValue.street[0];
            var street1     = quote.shippingAddress._latestValue.street[1];
            var street2     = quote.shippingAddress._latestValue.street[2];
            var country     = quote.shippingAddress._latestValue.countryId;
            var postcode    = quote.shippingAddress._latestValue.postcode;
            var city        = quote.shippingAddress._latestValue.postcode;

            return {
                data: {
                    "street0": street0 ? street0 : '',
                    "street1": street1 ? street1 : '',
                    "street2": street2 ? street2 : '',
                    "country": country ? country : '',
                    "postcode": postcode ? postcode : '',
                    "city": city ? city : '',
                }
            }
        }

        function getNoLoggedInAddress(fieldsetName) {
            var street0     = registry.get(fieldsetName + 'street.0');
            var street1     = registry.get(fieldsetName + 'street.1');
            var street2     = registry.get(fieldsetName + 'street.2');
            var country     = registry.get(fieldsetName + 'country_id');
            var postcode    = registry.get(fieldsetName + 'postcode');
            var city        = registry.get(fieldsetName + 'city');

            return {
                data: {
                    "street0": street0 ? street0.get('value') : '',
                    "street1": street1 ? street1.get('value') : '',
                    "street2": street2 ? street2.get('value') : '',
                    "country": country ? country.get('value') : '',
                    "postcode": postcode ? postcode.get('value') : '',
                    "city": city ? city.get('value') : '',
                }
            }
        }

        /**
         * Use the validated address data from POSTCODE.NL
         * @returns {* | jQuery}
         */
        function getPostcodeValidateAddress() {
            var validatedAddress = jQuery(".postcode-valid-address").text();

            if (jQuery("select[name='country_id']").val() === 'BE') {
                validatedAddress = jQuery('#shipping-be-street').val() + ' ' + jQuery('#shipping-be-house').val();
            }
            return validatedAddress
        }

        function showOptions() {
            originalShippingRate = jQuery("td[id^='label_carrier_" + window.mypa.data.general.parent_method + "']").parent();
            optionsContainer.show();

            if (typeof originalShippingRate !== 'undefined') {
                originalShippingRate.hide();
            }
        }

        function hideOptions() {
            if (typeof optionsContainer !== 'undefined') {
                optionsContainer.hide();
            }
            jQuery(myparcel_method_element + ':first').parent().parent().show();
        }

        function _hideRadios() {
            jQuery("td[id^='label_method_signature'],td[id^='label_method_mailbox'],td[id^='label_method_digital_stamp'],td[id^='label_method_pickup'],td[id^='label_method_evening'],td[id^='label_method_only_recipient'],td[id^='label_method_morning']").parent().hide();
        }

        function _getCcIsLocal() {
            if (window.mypa.address.cc !== 'NL' && window.mypa.address.cc !== 'BE' ) {
                return false;
            }

            return true;
        }

        function _getFullStreet() {
            return (window.mypa.address.street0 + ' ' + window.mypa.address.street1 + ' ' + window.mypa.address.street2).trim();
        }

        function _getHouseNumber() {
            var fullStreet = _getFullStreet();
            var streetParts = fullStreet.match(/[^\d]+([0-9]{1,4})[^\d]*/);
            if (streetParts !== null) {
                return streetParts[1];
            } else {
                var streetParts = fullStreet.match(/(.*?)\s?(([\d]+)[\s|-]?([a-zA-Z/\s]{0,5}$|[0-9/]{0,5}$|\s[a-zA-Z]{1}[0-9]{0,3}$|\s[0-9]{2}[a-zA-Z]{0,3}$))$/);
                return streetParts !== null ? streetParts[3] : null;
            }
        }

        function _observeFields() {
            delivery_options_input = jQuery("input[name='delivery_options']");

            jQuery("input[id^='s_method']").parent().on('change', function (event) {
                setTimeout(function(){
                    if (jQuery(myparcel_method_element + ':checked').length === 0) {
                        delivery_options_input.val('');
                        MyParcel.optionsHaveBeenModified();
                    }
                }, 50);
            });

            jQuery("input[name^='street'],input[name='postcode'],input[name^='pc_postcode'],select[name^='pc_postcode']").on('change', function (event) {
                setTimeout(function(){
                    checkAddress();
                }, 100);
            });

            delivery_options_input.on('change', function (event) {
                _checkShippingMethod();
            });
        }

        function _setParameters() {
            var data = {
                address: {
                    cc: window.mypa.address.cc,
                    street: _getFullStreet(),
                    postalCode: window.mypa.address.postcode,
                    number: _getHouseNumber(),
                    city: window.mypa.address.city
                },
                txtWeekDays: [
                    'Zondag',
                    'Maandag',
                    'Dinsdag',
                    'Woensdag',
                    'Donderdag',
                    'Vrijdag',
                    'Zaterdag'
                ],
                translateENtoNL: {
                    'monday': 'maandag',
                    'tuesday': 'dindsag',
                    'wednesday': 'woensdag',
                    'thursday': 'donderdag',
                    'friday': 'vrijdag',
                    'saturday': 'zaterdag',
                    'sunday': 'zondag'
                },
                config: {
                    "apiBaseUrl": "https://api.myparcel.nl/",
                    "carrier": "1",

                    "priceMorningDelivery":  window.mypa.data.morning.fee,
                    "priceStandardDelivery": window.mypa.data.general.base_price,
                    "priceEveningDelivery": window.mypa.data.evening.fee,
                    "priceSignature": window.mypa.data.delivery.signature_fee,
                    "priceOnlyRecipient":window.mypa.data.delivery.only_recipient_fee,
                    "pricePickup": window.mypa.data.pickup.fee,
                    "pricePickupExpress": window.mypa.data.pickup_express.fee,

                    "deliveryTitle": window.mypa.data.delivery.delivery_title,
                    "pickupTitle": window.mypa.data.pickup.title,
                    "deliveryMorningTitle": window.mypa.data.morning.title,
                    "deliveryStandardTitle": window.mypa.data.delivery.standard_delivery_title,
                    "deliveryEveningTitle": window.mypa.data.evening.title,
                    "signatureTitle": window.mypa.data.delivery.signature_title,
                    "onlyRecipientTitle": window.mypa.data.delivery.only_recipient_title,

                    "allowMondayDelivery": window.mypa.data.general.monday_delivery_active,
                    "allowMorningDelivery": window.mypa.data.morning.active,
                    "allowEveningDelivery": window.mypa.data.evening.active,
                    "allowSignature": window.mypa.data.delivery.signature_active,
                    "allowOnlyRecipient": window.mypa.data.delivery.only_recipient_active,
                    "allowPickupPoints": window.mypa.data.pickup.active,
                    "allowPickupExpress": window.mypa.data.pickup_express.active,

                    "dropOffDays": window.mypa.data.general.dropoff_days,
                    "saturdayCutoffTime": window.mypa.data.general.saturday_cutoff_time,
                    "cutoffTime": window.mypa.data.general.cutoff_time,
                    "deliverydaysWindow": window.mypa.data.general.deliverydays_window,
                    "dropoffDelay":window.mypa.data.general.dropoff_delay,

                    "AllowBelgiumPickup": window.mypa.data.belgium_pickup.active,
                    "BelgiumDeliveryTitle": window.mypa.data.belgium_pickup.title,
                    "BelgiumDeliveryStandardTitle": window.mypa.data.belgium_pickup.fee
                }

            };
            MyParcel.init(data);
        }

        function _appendTemplate() {
            if (jQuery('#myparcel_td').length === 0) {
                var data = window.mypa.data;
                var baseColor = data.general.color_base;
                var selectColor = data.general.color_select;
                cssDynamic = cssDynamic.replace(/_base_color_/g, baseColor).replace(/_select_color_/g, selectColor);
                optionsHtml = optionsHtml.replace('<css-dynamic/>', cssDynamic);

                originalShippingRate = jQuery("td[id^='label_carrier_" + window.mypa.data.general.parent_method + "']").parent();
                optionsContainer = originalShippingRate.parent().prepend('<tr><td colspan="5" id="myparcel_td" >Bezig met laden...</td></tr>').find('#myparcel_td');

                optionsContainer.html(optionsHtml);
                jQuery('#mypa-pickup_title').html(data.pickup.title);
                jQuery('#mypa-delivery_title').html(data.delivery.delivery_title);

            }
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
                        _checkMethod('input[value=' + myparcel_method_alias + '_morning_signature' + ']');
                    } else {
                        _checkMethod('input[value=' + myparcel_method_alias + '_morning' + ']');
                    }
                    myparcel.showDays();
                    break;
                case "standard":
                    if (json.options.signature && json.options.only_recipient) {
                        _checkMethod('input[value=' + myparcel_method_alias + '_signature_only_recip' + ']');
                    } else {
                        if (json.options.signature) {
                            _checkMethod('input[value=' + myparcel_method_alias + '_signature' + ']');
                        } else if (json.options.only_recipient) {
                            _checkMethod('input[value=' + myparcel_method_alias + '_only_recipient' + ']');
                        } else {
                            _checkMethod('input[value=' + myparcel_method_alias + '_' + window.mypa.data.general.parent_method + ']');
                        }
                    }
                    myparcel.showDays();
                    break;
                case "night":
                    if (json.options.signature) {
                        _checkMethod('input[value=' + myparcel_method_alias + '_evening_signature' + ']');
                    } else {
                        _checkMethod('input[value=' + myparcel_method_alias + '_evening' + ']');
                    }
                    myparcel.showDays();
                    break;
                case "retail":
                    _checkMethod('input[value=' + myparcel_method_alias + '_pickup' + ']');
                    myparcel.hideDays();
                    break;
                case "retailexpress":
                    _checkMethod('input[value=' + myparcel_method_alias + '_pickup_express' + ']');
                    myparcel.hideDays();
                    break;
                case "mailbox":
                    _checkMethod('input[value=' + myparcel_method_alias + '_mailbox' + ']');
                    myparcel.hideDays();
                    break;
            }
        }

        function _checkMethod(selector) {
            jQuery(".col-method > input[type='radio']").prop("checked", false).change();
            jQuery(selector).prop("checked", true).change().trigger('click');
        }
    }
);
