var config = {
    map: {
        '*': {
            'Magento_Tax/js/view/checkout/shipping_method/price': 'MyParcelNL_Magento/js/action/price',
            "Magento_Checkout/js/model/shipping-save-processor/default" : "MyParcelNL_Magento/js/model/shipping-save-processor-default",
            'myparcelnl_init_shipping_options': 'MyParcelNL_Magento/js/checkout/shipping_method/show-myparcel-shipping-method',
            'myparcelnl_lib_myparcel': 'MyParcelNL_Magento/js/lib/myparcel',
            'myparcelnl_options_template': 'text!MyParcelNL_Magento/template/checkout/options.html',
            'myparcelnl_options_css-dynamic': 'text!MyParcelNL_Magento/css/checkout/options-dynamic.min.css'
        }
    }
};
