<?php

declare(strict_types=1);

/**
 * Save delivery date and delivery options
 *
 * Plugin from Magento\Checkout\Model\ShippingInformationManagement
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Helper\ValidatePostalCode;
use MyParcelNL\Sdk\Helper\ValidateStreet;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\Support\Str;

class SaveOrderBeforeSalesModelQuoteObserver implements ObserverInterface
{
    private DeliveryRepository $delivery;

    /**
     * SaveOrderBeforeSalesModelQuoteObserver constructor.
     *
     * @param DeliveryRepository $delivery
     */
    public function __construct(
        DeliveryRepository $delivery
    )
    {
        $this->delivery = $delivery;
    }

    /**
     *
     * @param Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /* @var Quote $quote */
        $quote = $observer->getEvent()->getData('quote');

        /* @var Order $order */
        $order = $observer->getEvent()->getData('order');

        if ($order->getShippingAddress() === null) {
            return $this;
        }

        $fullStreet         = implode(' ', $order->getShippingAddress()->getStreet() ?? []);
        $postcode           = $order->getShippingAddress()->getPostcode();
        $destinationCountry = $order->getShippingAddress()->getCountryId();

        if (!ValidateStreet::validate($fullStreet, AbstractConsignment::CC_NL, $destinationCountry)) {
            $order->setData(Config::FIELD_TRACK_STATUS, __('⚠️&#160; Please check street'));
        }

        if (!ValidatePostalCode::validate($postcode, $destinationCountry)) {
            $order->setData(Config::FIELD_TRACK_STATUS, __('⚠️&#160; Please check postal code'));
        }

        if ($quote->hasData(Config::FIELD_DELIVERY_OPTIONS) && $this->hasMyParcelDeliveryOptions($quote)) {
            $jsonDeliveryOptions = $quote->getData(Config::FIELD_DELIVERY_OPTIONS) ?? '';
            $deliveryOptions     = json_decode($jsonDeliveryOptions, true) ?? [];

            $order->setData(Config::FIELD_DELIVERY_OPTIONS, $jsonDeliveryOptions);

            $dropOffDay = $this->delivery->getDropOffDayFromDeliveryOptions($deliveryOptions);
            $order->setData(Config::FIELD_DROP_OFF_DAY, $dropOffDay);

            $selectedCarrier = $this->delivery->getCarrierFromDeliveryOptions($deliveryOptions);
            $order->setData(Config::FIELD_MYPARCEL_CARRIER, $selectedCarrier);
        }

        return $this;
    }

    /**
     * @param Quote $quote
     *
     * @return bool
     */
    private function hasMyParcelDeliveryOptions(Quote $quote): bool
    {
        $shippingMethod = $quote->getShippingAddress()->getShippingMethod();

        if (Str::startswith($shippingMethod, Carrier::CODE)) {
            return true;
        }

        return array_key_exists(Config::FIELD_DELIVERY_OPTIONS, $quote->getData());
    }
}
