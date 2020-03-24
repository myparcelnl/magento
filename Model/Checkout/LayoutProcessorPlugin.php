<?php
/**
 * Set MyParcel data field in checkout
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelBE\Magento\Model\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use MyParcelBE\Magento\Helper\Checkout as CheckoutHelper;
use MyParcelBE\Magento\Model\Quote\Checkout;

class LayoutProcessorPlugin
{
    /**
     * @var \MyParcelBE\Magento\Model\Quote\Checkout
     */
    private $settings;

    /**
     * @param Checkout $settings
     */
    public function __construct(Checkout $settings)
    {
        $this->settings = $settings;
    }
    /**
     * @param \Magento\Checkout\Block\Checkout\LayoutProcessor $subject
     * @param array                                            $jsLayout
     *
     * @return array
     */
    public function afterProcess(LayoutProcessor $subject, array  $jsLayout) {
        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
        ['children']['shippingAddress']['children']['before-shipping-method-form']['children'] =
            array_merge_recursive(
                $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
                ['children']['shippingAddress']['children']['before-shipping-method-form']['children'],
                [
                    CheckoutHelper::FIELD_DELIVERY_OPTIONS => [
                        'component' => 'Magento_Ui/js/form/element/abstract',
                        'config' => [
                            'customScope' => 'shippingAddress',
                            'template' => 'ui/form/field',
                            'elementTmpl' => 'ui/form/element/input',
                            'options' => [],
                            'id' => 'myparcel-delivery-options',
                        ],
                        'dataScope' => 'shippingAddress.' . CheckoutHelper::FIELD_DELIVERY_OPTIONS,
                        'label' => 'Delivery Options',
                        'provider' => 'checkoutProvider',
                        'visible' => false,
                        'validation' => [],
                        'sortOrder' => 200,
                        'id' => 'myparcel-delivery-options',
                    ],
                ]
            );

        return $jsLayout;
    }
}
