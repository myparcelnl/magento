<?php
/**
 * Created by PhpStorm.
 * User: reindert
 * Date: 01/06/2017
 * Time: 14:51
 */

namespace MyParcelNL\Magento\Model\Quote;


use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

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
     * @var PackageRepository
     */
    private $package;

    /**
     * Checkout constructor.
     * @param \Magento\Checkout\Model\Session $session
     * @param \MyParcelNL\Magento\Helper\Checkout $helper
     * @param PackageRepository $package
     */
    public function __construct(
        \Magento\Checkout\Model\Session $session,
        \MyParcelNL\Magento\Helper\Checkout $helper,
        PackageRepository $package
    ) {
        $this->helper = $helper;
        $this->quote = $session->getQuote();
        $this->package = $package;
        $this->package->setMailboxSettings();
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
            'mailbox' => $this->getMailboxData(),
            'pickup' => $this->getPickupData(),
            'pickup_express' => $this->getPickupExpressData(),
        ];

        $this
            ->setExcludeDeliveryTypes();

        return ['root' => [
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

        return [
            'base_price' => $this->helper->getMoneyFormat($this->helper->getBasePrice()),
            'cutoff_time' => $this->helper->getTimeConfig('cutoff_time'),
            'deliverydays_window' => $this->helper->getIntergerConfig('deliverydays_window'),
            'dropoff_days' => $this->helper->getArrayConfig('dropoff_days'),
            'monday_delivery_active' => $this->helper->getBoolConfig('monday_delivery_active'),
            'saturday_cutoff_time' => $this->helper->getTimeConfig('saturday_cutoff_time'),
            'dropoff_delay' => $this->helper->getIntergerConfig('dropoff_delay'),
            'color_base' => $this->helper->getCheckoutConfig('color_base'),
            'color_select' => $this->helper->getCheckoutConfig('color_select'),
            'parent_method' => $this->helper->getParentRateFromQuote($this->quote),
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

        $deliveryData = [
            'delivery_title' => $this->helper->getCheckoutConfig('delivery_title'),
            'only_recipient_active' => $this->helper->getBoolConfig('only_recipient_active'),
            'only_recipient_title' => $this->helper->getCheckoutConfig('only_recipient_title'),
            'only_recipient_fee' => $this->helper->getMethodPriceFormat('only_recipient_fee', false, '+ '),
            'signature_active' => $this->helper->getBoolConfig('signature_active'),
            'signature_title' => $this->helper->getCheckoutConfig('signature_title'),
            'signature_fee' => $this->helper->getMethodPriceFormat('signature_fee', false, '+ '),
            'signature_and_only_recipient_fee' => $this->helper->getMethodPriceFormat('signature_and_only_recipient_fee', false, '+ '),
        ];

        if ($deliveryData['signature_active'] === false) {
            $deliveryData['signature_fee'] = 'disabled';
        }

        if ($deliveryData['only_recipient_active'] === false) {
            $deliveryData['only_recipient_fee'] = 'disabled';
        }

        return $deliveryData;
    }

    /**
     * Get morning data
     *
     * @return array)
     */
    private function getMorningData()
    {
        $this->helper->setTmpScope('morning');

        return [
            'active' => $this->helper->getBoolConfig('active'),
            'fee' => $this->helper->getMethodPriceFormat('fee'),
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

        return [
            'active' => $this->helper->getBoolConfig('active'),
            'fee' => $this->helper->getMethodPriceFormat('fee'),
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

        return [
            'active' => $this->helper->getBoolConfig('active'),
            'title' => $this->helper->getCheckoutConfig('title'),
            'fee' => $this->helper->getMethodPriceFormat('fee'),
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

        return [
            'active' => $this->helper->getCheckoutConfig('active'),
            'fee' => $this->helper->getMethodPriceFormat('fee'),
        ];
    }

    /**
     * @return array
     */
    private function getMailboxData()
    {
        /** @var \Magento\Quote\Model\Quote\Item[] $products */
        $this->helper->setTmpScope('mailbox');
        $products = $this->quote->getAllItems();

        if (count($products) > 0){
            $this->package->setWeightFromQuoteProducts($products);
        }

        /** check if mailbox is active */
        $mailboxData = [
            'active' => $this->package->fitInMailbox(),
            'title' => $this->helper->getCheckoutConfig('title'),
            'fee' => $this->helper->getMethodPriceFormat('fee', false),
        ];

        if ($mailboxData['active'] === false) {
            $mailboxData['fee'] = 'disabled';
        }

        return $mailboxData;
    }

    /**
     * This options allows the Merchant to exclude delivery types
     *
     * @return $this
     */
    private function setExcludeDeliveryTypes()
    {
        $excludeDeliveryTypes = [];

        if ($this->data['morning']['active'] == false) {
            $excludeDeliveryTypes[] = '1';
        }

        if ($this->data['evening']['active'] == false) {
            $excludeDeliveryTypes[] = '3';
        }

        if ($this->data['pickup']['active'] == false) {
            $excludeDeliveryTypes[] = '4';
        }

        if ($this->data['pickup_express']['active'] == false) {
            $excludeDeliveryTypes[] = '5';
        }

        $result = implode(';', $excludeDeliveryTypes);

        $this->data['general']['exclude_delivery_types'] = $result;

        return $this;
    }
}