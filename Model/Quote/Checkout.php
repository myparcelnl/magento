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
     * Checkout constructor.
     * @param \MyParcelNL\Magento\Helper\Checkout $helper
     */
    public function __construct(\MyParcelNL\Magento\Helper\Checkout $helper)
    {
        $this->helper = $helper;
    }


    /**
     * Get settings for MyParcel checkout
     *
     * @param double $basePrice
     *
     * @return array
     */
    public function getCheckoutSettings($basePrice)
    {
        $this->helper->setBasePrice($basePrice);

        $this->data = [
            'general' => $this->getGeneralData(),
            'delivery' => $this->getDeliveryData(),
            'morning' => $this->getMorningData(),
            'evening' => $this->getEveningData(),
            'pickup' => $this->getPickupData(),
            'pickupExpress' => $this->getPickupExpressData(),
        ];

        return [
            'version' => (string)$this->helper->getVersion(),
            'data' => (object)$this->data
        ];
    }

    /**
     * Get general data
     *
     * @return object
     */
    private function getGeneralData()
    {
        return (object)[
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
     * @return object
     */
    private function getDeliveryData()
    {
        return (object)[
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
     * @return object
     */
    private function getMorningData()
    {
        return (object)[
            'active' => $this->helper->getBoolConfig('morningdelivery_active'),
            'fee' => $this->helper->getExtraPrice('morningdelivery_fee'),
        ];
    }

    /**
     * Get evening data
     *
     * @return object
     */
    private function getEveningData()
    {
        return (object)[
            'active' => $this->helper->getBoolConfig('eveningdelivery_active'),
            'fee' => $this->helper->getExtraPrice('eveningdelivery_fee'),
        ];
    }

    /**
     * Get pickup data
     *
     * @return object
     */
    private function getPickupData()
    {
        return (object)[
            'active' => $this->helper->getBoolConfig('pickup_active'),
            'title' => $this->helper->getCheckoutConfig('pickup_title'),
            'fee' => $this->helper->getExtraPrice('pickup_fee'),
        ];
    }

    /**
     * Get pickup express data
     *
     * @return object
     */
    private function getPickupExpressData()
    {
        return (object)[
            'active' => $this->helper->getCheckoutConfig('pickup_express_active'),
            'fee' => $this->helper->getExtraPrice('pickup_express_fee'),
        ];
    }
}