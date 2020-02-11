<?php
/**
 * Created by PhpStorm.
 * User: reindert
 * Date: 01/06/2017
 * Time: 14:51
 */

namespace MyParcelNL\Magento\Model\Quote;

use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

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
    private $quoteId;
    /**
     * @var PackageRepository
     */
    private $package;

    /**
     * @var \Magento\Eav\Model\Entity\Collection\AbstractCollection[]
     */
    private $products;

    /**
     * @var DefaultOptions
     */
    private $defaultOptions;

    /**
     * Checkout constructor.
     *
     * @param \Magento\Checkout\Model\Session     $session
     * @param \Magento\Checkout\Model\Cart        $cart
     * @param \MyParcelNL\Magento\Helper\Checkout $helper
     * @param PackageRepository                   $package
     */
    public function __construct(
        \Magento\Checkout\Model\Session $session,
        \Magento\Checkout\Model\Cart $cart,
        \MyParcelNL\Magento\Helper\Checkout $helper,
        PackageRepository $package
    ) {
        $this->helper         = $helper;
        $this->quoteId        = $session->getQuoteId();
        $this->products       = $cart->getItems();
        $this->package        = $package;
        $this->defaultOptions = new DefaultOptions($session->getQuote(), $helper);
        $this->package->setCurrentCountry($session->getQuote()->getShippingAddress()->getCountryId());
        $this->package->setMailboxSettings();
        $this->package->setDigitalStampSettings();
    }

    /**
     * Get settings for MyParcel checkout
     *
     * @return array
     */
    public function getCheckoutSettings()
    {
        $this->helper->setBasePriceFromQuote($this->quoteId);

        $this->data = [
            'general'        => $this->getGeneralData(),
            'delivery'       => $this->getDeliveryData(),
            'morning'        => $this->getMorningData(),
            'evening'        => $this->getEveningData(),
            'mailbox'        => $this->getMailboxData(),
            'digital_stamp'  => $this->getDigitalStampData(),
            'pickup'         => $this->getPickupData(),
            'belgium_pickup' => $this->getBelgiumPickupData(),
        ];

        $this
            ->setExcludeDeliveryTypes();

        return [
            'root' => [
                'version' => (string) $this->helper->getVersion(),
                'data'    => (array) $this->data
            ]
        ];
    }

    /**
     * Get general data
     *
     * @return array)
     */
    private function getGeneralData()
    {
        if ($this->package->getDisableCheckout()) {
            return [];
        }

        return [
            'base_price'             => $this->helper->getMoneyFormat($this->helper->getBasePrice()),
            'cutoff_time'            => $this->helper->getTimeConfig('general/cutoff_time'),
            'deliverydays_window'    => $this->helper->getIntergerConfig('general/deliverydays_window'),
            'dropoff_days'           => $this->helper->getArrayConfig('general/dropoff_days'),
            'monday_delivery_active' => $this->helper->getBoolConfig('general/monday_delivery_active'),
            'saturday_cutoff_time'   => $this->helper->getTimeConfig('general/saturday_cutoff_time'),
            'dropoff_delay'          => $this->helper->getIntergerConfig('general/dropoff_delay'),
            'color_base'             => $this->helper->getCheckoutConfig('general/color_base'),
            'color_select'           => $this->helper->getCheckoutConfig('general/color_select'),
            'parent_carrier'         => $this->helper->getParentCarrierNameFromQuote($this->quoteId),
            'parent_method'          => $this->helper->getParentMethodNameFromQuote($this->quoteId),
        ];
    }

    /**
     * Get delivery data
     *
     * @return array)
     */
    private function getDeliveryData()
    {
        $deliveryData = [
            'delivery_title'                   => $this->helper->getCheckoutConfig('delivery/delivery_title'),
            'standard_delivery_title'          => $this->helper->getCheckoutConfig('delivery/standard_delivery_title'),
            'only_recipient_active'            => $this->hasAgeCheck() ? false : $this->helper->getBoolConfig('delivery/only_recipient_active'),
            'only_recipient_title'             => $this->helper->getCheckoutConfig('delivery/only_recipient_title'),
            'only_recipient_fee'               => $this->helper->getMethodPriceFormat('delivery/only_recipient_fee', false, '+ '),
            'signature_active'                 => $this->hasAgeCheck() ? false : $this->helper->getBoolConfig('delivery/signature_active'),
            'signature_title'                  => $this->helper->getCheckoutConfig('delivery/signature_title'),
            'signature_fee'                    => $this->helper->getMethodPriceFormat('delivery/signature_fee', false, '+ '),
            'signature_and_only_recipient_fee' => $this->helper->getMethodPriceFormat('delivery/signature_and_only_recipient_fee', false, '+ '),
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
     * @return bool
     */
    private function hasAgeCheck()
    {
        return $this->defaultOptions->getDefault('age_check');
    }

    /**
     * Get morning data
     *
     * @return array)
     */
    private function getMorningData()
    {
        return [
            'active' => $this->hasAgeCheck() ? false : $this->helper->getBoolConfig('morning/active'),
            'title'  => $this->helper->getCheckoutConfig('morning/title'),
            'fee'    => $this->helper->getMethodPriceFormat('morning/fee'),
        ];
    }

    /**
     * Get evening data
     *
     * @return array)
     */
    private function getEveningData()
    {
        return [
            'active' => $this->hasAgeCheck() ? false : $this->helper->getBoolConfig('evening/active'),
            'title'  => $this->helper->getCheckoutConfig('evening/title'),
            'fee'    => $this->helper->getMethodPriceFormat('evening/fee'),
        ];
    }

    /**
     * Get pickup data
     *
     * @return array)
     */
    private function getPickupData()
    {
        return [
            'active' => $this->helper->getBoolConfig('pickup/active'),
            'title'  => $this->helper->getCheckoutConfig('pickup/title'),
            'fee'    => $this->helper->getMethodPriceFormat('pickup/fee'),
        ];
    }

    /**
     * Get Belgium pickup data
     *
     * @return array)
     */
    private function getBelgiumPickupData()
    {
        if (empty($this->helper->getCheckoutConfig('belgium_pickup/active'))) {
            return [
                'active' => 0,
                'title'  => "",
                'fee'    => 0,
            ];
        }

        return [
            'active' => $this->helper->getCheckoutConfig('belgium_pickup/active'),
            'title'  => $this->helper->getCheckoutConfig('belgium_pickup/title'),
            'fee'    => $this->helper->getMethodPriceFormat('belgium_pickup/fee'),
        ];
    }

    /**
     * @return array
     */
    private function getMailboxData()
    {
        /** @var \Magento\Quote\Model\Quote\Item[] $products */
        $this->package->setWeightFromQuoteProducts($this->products, 'fit_in_mailbox');

        /** check if mailbox is active */
        $mailboxData = [
            'active'                => $this->package->fitInMailbox(),
            'mailbox_other_options' => $this->package->isShowMailboxWithOtherOptions(),
            'title'                 => $this->helper->getCheckoutConfig('mailbox/title'),
            'fee'                   => $this->helper->getMethodPriceFormat('mailbox/fee', false),
        ];

        return $mailboxData;
    }

    /**
     * @return array
     */
    private function getDigitalStampData()
    {
        /** @var \Magento\Quote\Model\Quote\Item[] $products */
        $this->package->setWeightFromQuoteProducts($this->products, 'digital_stamp');

        /** check if digital stamp is active */
        $digitalStampData = [
            'active' => $this->package->fitInDigitalStamp(),
            'title'  => $this->helper->getCheckoutConfig('digital_stamp/title'),
            'fee'    => $this->helper->getMethodPriceFormat('digital_stamp/fee', false),
        ];

        return $digitalStampData;
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

        $result = implode(';', $excludeDeliveryTypes);

        $this->data['general']['exclude_delivery_types'] = $result;

        return $this;
    }
}
