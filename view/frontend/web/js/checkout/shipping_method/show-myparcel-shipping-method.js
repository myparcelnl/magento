define(
    [
        'mage/url',
        'uiComponent',
        'jquery',
        'myparcelnl_options_template',
        'myparcelnl_options_css',
        'myparcelnl_lib_myparcel',
        'myparcelnl_lib_moment',
        'myparcelnl_lib_webcomponents'
    ],
    function(mageUrl, uiComponent, jQuery, optionsHtml, optionsCss) {
        'use strict';

        var  originalShippingRate, optionsContainer, isLoading, myparcel, delivery_options_input, myparcel_method_alias, myparcel_method_element;

        return {
            loadOptions: loadOptions,
            showOptions: showOptions,
            hideOptions: hideOptions
        };

        function loadOptions() {
            if (typeof window.mypa === 'undefined') {
                window.mypa = {isLoading: false};
            }
            if (window.mypa.isLoading === false) {
                window.mypa.isLoading = true;
                isLoading = setTimeout(function(){
                    clearTimeout(isLoading);

                    jQuery.ajax({
                        url: mageUrl.build('rest/V1/delivery_settings/get'),
                        type: "GET",
                        dataType: 'json'
                    }).done(function (response) {
                        window.mypa.data = response[0].data;
                        init();
                        window.mypa.isLoading = false;
                    });

                }, 50);
            }
        }

        function init() {
            if ((myparcel_method_alias = window.mypa.data.general.parent_method) === null) {
                hideOptions();
                return void 0;
            }

            myparcel_method_element = "input[id^='s_method_" + myparcel_method_alias + "_']";

            checkAddress();
        }

        function checkAddress() {
            window.mypa.address = [];
            window.mypa.address.cc = jQuery("select[name='country_id']").val();
            window.mypa.address.street0 = jQuery("input[name='street[0]']").val();
            window.mypa.address.street1 = jQuery("input[name='street[1]']").val();
            window.mypa.address.street2 = jQuery("input[name='street[2]']").val();
            window.mypa.address.postcode = jQuery("input[name='postcode']").val();

            _hideRadios();
            _appendTemplate();
            _setParameters();

            if (myParcelOptionsActive()) {
                showOptions();
            } else {
                console.log('hideoptions');
                console.log(window.mypa.address.cc);
                jQuery(myparcel_method_element + ":first").parent().parent().show();
                hideOptions();
            }
        }

        function showOptions() {
            originalShippingRate.hide();
            optionsContainer.show();
        }

        function hideOptions() {
            originalShippingRate.show();
            optionsContainer.hide();
        }

        function _hideRadios() {
            jQuery(myparcel_method_element).parent().parent().hide();
        }

        function myParcelOptionsActive() {
            if (window.mypa.address.cc !== 'NL') {
                return false;
            }

            return true;
        }

        function _getHouseNumber() {
            console.log(window.mypa.address);
            var fullStreet = (window.mypa.address.street0 + ' ' + window.mypa.address.street1 + ' ' + window.mypa.address.street2).trim();
            var arr = fullStreet.match(/(.*?)([0-9]{1,4})(.*?)/);
            console.log(arr);

            return arr[3];
        }

        function _observeFields() {
            delivery_options_input = jQuery("input[name='delivery_options']");

            jQuery("input[id^='s_method']").parent().on('change', function (event) {
                setTimeout(function(){
                    if (jQuery(myparcel_method_element + ':checked').length === 0) {
                        delivery_options_input.val('');
                        myparcel.optionsHaveBeenModified();
                    }
                }, 50);
            });

            delivery_options_input.on('change', function (event) {
                _checkShippingMethod();
            });
        }

        function _setParameters() {
            var data = window.mypa.data;
            window.mypa.settings = {
                deliverydays_window: 10,
                number: _getHouseNumber(),
                street: window.mypa.address.postcode,
                postal_code: window.mypa.address.postcode,
                price: {
                    morning: data.morning.fee,
                    default: data.general.base_price,
                    night: data.evening.fee,
                    pickup: data.pickup.fee,
                    pickup_express: data.pickup_express.fee,
                    signed: data.delivery.signature_fee,
                    only_recipient: data.delivery.only_recipient_fee,
                    combi_options: data.delivery.signature_and_only_recipient_fee,
                    mailbox: data.mailbox.fee,
                    exclude_delivery_type: data.general.exclude_delivery_types
                },
                base_url: 'https://api.myparcel.nl/delivery_options',
                text:
                    {
                        signed: data.delivery.signature_title,
                        only_recipient: data.delivery.only_recipient_title
                    }
            };

            myparcel = new MyParcel();
            myparcel.updatePage();
        }

        function _appendTemplate() {
            if (jQuery('#myparcel_td').length === 0) {
                var data = window.mypa.data;
                var baseColor = data.general.color_base;
                var selectColor = data.general.color_select;
                optionsCss = optionsCss.replace(/_base_color_/g, baseColor).replace(/_select_color_/g, selectColor);
                optionsHtml = optionsHtml.replace('<css/>', optionsCss);

                console.log(myparcel_method_alias);
                originalShippingRate = jQuery("td[id^='label_carrier_" + myparcel_method_alias + "_']").parent().find('td');
                optionsContainer = originalShippingRate.parent().parent().prepend('<tr><td colspan="4" id="myparcel_td" style="display:none;"></td></tr>').find('#myparcel_td');
                optionsContainer.html(optionsHtml);

                _observeFields();
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
                        _checkMethod('#s_method_' + myparcel_method_alias + '_morning_signature');
                    } else {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_morning');
                    }
                    myparcel.showDays();
                    break;
                case "standard":
                    if (json.options.signature && json.options.only_recipient) {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_signature_only_recip');
                    } else {
                        if (json.options.signature) {
                            _checkMethod('#s_method_' + myparcel_method_alias + '_signature');
                        } else if (json.options.only_recipient) {
                            _checkMethod('#s_method_' + myparcel_method_alias + '_only_recipient');
                        } else {
                            _checkMethod('#s_method_flatrate_flatrate');
                        }
                    }
                    myparcel.showDays();
                    break;
                case "night":
                    if (json.options.signature) {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_evening_signature');
                    } else {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_evening');
                    }
                    myparcel.showDays();
                    break;
                case "retail":
                    _checkMethod('#s_method_' + myparcel_method_alias + '_pickup');
                    myparcel.hideDays();
                    break;
                case "retailexpress":
                    _checkMethod('#s_method_' + myparcel_method_alias + '_pickup_express');
                    myparcel.hideDays();
                    break;
                case "mailbox":
                    _checkMethod('#s_method_' + myparcel_method_alias + '_mailbox');
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