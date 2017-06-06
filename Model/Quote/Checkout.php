<?php
/**
 * Created by PhpStorm.
 * User: reindert
 * Date: 01/06/2017
 * Time: 14:51
 */

namespace MyParcelNL\Magento\Model\Quote;


class Checkout
{
    /**
     * @var array
     */
    private $data = [];

    /**
     * @var \MyParcelNL\Magento\Helper\Checkout
     */
    private $helper;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Checkout constructor.
     * @param \Magento\Checkout\Model\Session $session
     * @param \MyParcelNL\Magento\Helper\Checkout $helper
     */
    public function __construct(
        \Magento\Checkout\Model\Session $session,
        \MyParcelNL\Magento\Helper\Checkout $helper
    ) {
        $this->helper = $helper;
        $this->quote = $session->getQuote();
    }

    /**
     * Get settings for MyParcel checkout
     *
     * @return array
     */
    public function getCheckoutSettings()
    {

        $this->helper->setBasePriceFromQuote($this->quote);

        $this->data = [
            'general' => $this->getGeneralData(),
            'delivery' => $this->getDeliveryData(),
            'morning' => $this->getMorningData(),
            'evening' => $this->getEveningData(),
            'pickup' => $this->getPickupData(),
            'pickupExpress' => $this->getPickupExpressData(),
        ];

        return ['data' => [
            'version' => (string)$this->helper->getVersion(),
            'data' => (array)$this->data
        ]];
    }

    /**
     * Get general data
     *
     * @return array)
     */
    private function getGeneralData()
    {
        $this->helper->setTmpScope('general');

        return (array)[
            'base_price' => $this->helper->getBasePrice(),
            'cutoff_time' => $this->helper->getTimeConfig('cutoff_time'),
            'deliverydays_window' => $this->helper->getIntergerConfig('deliverydays_window'),
            'dropoff_days' => $this->helper->getArrayConfig('dropoff_days'),
            'monday_delivery_active' => $this->helper->getBoolConfig('monday_delivery_active'),
            'saturday_cutoff_time' => $this->helper->getTimeConfig('saturday_cutoff_time'),
            'dropoff_delay' => $this->helper->getIntergerConfig('dropoff_delay'),
            'base_color' => $this->helper->getCheckoutConfig('base_color'),
            'select_color' => $this->helper->getCheckoutConfig('select_color'),
        ];
    }

    /**
     * Get delivery data
     *
     * @return array)
     */
    private function getDeliveryData()
    {
        $this->helper->setTmpScope('delivery');

        return (array)[
            'delivery_title' => $this->helper->getCheckoutConfig('delivery_title'),
            'only_recipient_active' => $this->helper->getBoolConfig('only_recipient_active'),
            'only_recipient_title' => $this->helper->getCheckoutConfig('only_recipient_title'),
            'only_recipient_fee' => $this->helper->getExtraPrice('only_recipient_fee'),
            'signature_active' => $this->helper->getBoolConfig('signature_active'),
            'signature_title' => $this->helper->getCheckoutConfig('signature_title'),
            'signature_fee' => $this->helper->getExtraPrice('signature_fee'),
            'signature_and_only_recipient_fee' => $this->helper->getExtraPrice('signature_and_only_recipient_fee'),
        ];
    }

    /**
     * Get morning data
     *
     * @return array)
     */
    private function getMorningData()
    {
        $this->helper->setTmpScope('morning');

        return (array)[
            'active' => $this->helper->getBoolConfig('active'),
            'fee' => $this->helper->getExtraPrice('fee'),
        ];
    }

    /**
     * Get evening data
     *
     * @return array)
     */
    private function getEveningData()
    {
        $this->helper->setTmpScope('evening');

        return (array)[
            'active' => $this->helper->getBoolConfig('active'),
            'fee' => $this->helper->getExtraPrice('fee'),
        ];
    }

    /**
     * Get pickup data
     *
     * @return array)
     */
    private function getPickupData()
    {
        $this->helper->setTmpScope('pickup');

        return (array)[
            'active' => $this->helper->getBoolConfig('active'),
            'title' => $this->helper->getCheckoutConfig('title'),
            'fee' => $this->helper->getExtraPrice('fee'),
        ];
    }

    /**
     * Get pickup express data
     *
     * @return array)
     */
    private function getPickupExpressData()
    {
        $this->helper->setTmpScope('pickup_express');

        return (array)[
            'active' => $this->helper->getCheckoutConfig('active'),
            'fee' => $this->helper->getExtraPrice('fee'),
        ];
    }
}