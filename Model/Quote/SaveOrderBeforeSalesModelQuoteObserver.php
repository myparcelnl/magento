<?php
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
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Helper\Checkout as CheckoutHelper;
use MyParcelNL\Magento\Model\Checkout\Carrier;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Sdk\src\Helper\SplitStreet;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class SaveOrderBeforeSalesModelQuoteObserver implements ObserverInterface
{
    const FIELD_DELIVERY_OPTIONS = 'delivery_options';
    const FIELD_DROP_OFF_DAY = 'drop_off_day';
    const FIELD_TRACK_STATUS = 'track_status';
    /**
     * @var DeliveryRepository
     */
    private $delivery;
    /**
     * @var AbstractConsignment
     */
    private $consignment;
    /**
     * @var array
     */
    private $parentMethods;

    /**
     * SaveOrderBeforeSalesModelQuoteObserver constructor.
     *
     * @param DeliveryRepository                  $delivery
     * @param AbstractConsignment                 $consignment
     * @param \MyParcelNL\Magento\Helper\Checkout $checkoutHelper
     */
    public function __construct(
        DeliveryRepository $delivery,
        AbstractConsignment $consignment,
        CheckoutHelper $checkoutHelper
    ) {
        $this->delivery      = $delivery;
        $this->consignment   = $consignment;
        $this->parentMethods = explode(',', $checkoutHelper->getCheckoutConfig('general/shipping_methods'));
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
        /* @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getData('order');

        if ($order->getShippingAddress() === null) {
            return $this;
        }

        $fullStreet = implode(' ', $order->getShippingAddress()->getStreet());

        $destinationCountry = $order->getShippingAddress()->getCountryId();
        if ($destinationCountry == AbstractConsignment::CC_NL &&
            ! SplitStreet::isCorrectStreet($fullStreet, AbstractConsignment::CC_NL, $destinationCountry)
        ) {
            $order->setData(self::FIELD_TRACK_STATUS, __('⚠️&#160; Please check address'));
        }

        if ($quote->hasData(self::FIELD_DELIVERY_OPTIONS) && $this->isMyParcelMethod($quote)) {
            $jsonDeliveryOptions = $quote->getData(self::FIELD_DELIVERY_OPTIONS);
            $order->setData(self::FIELD_DELIVERY_OPTIONS, $jsonDeliveryOptions);

            $dropOffDay = $this->delivery->getDropOffDayFromJson($jsonDeliveryOptions);
            $order->setData(self::FIELD_DROP_OFF_DAY, $dropOffDay);
        }

        return $this;
    }

    /**
     * @param Quote $quote
     *
     * @return bool
     */
    private function isMyParcelMethod(Quote $quote): bool
    {
        $myParcelMethods = array_keys(Carrier::getMethods());
        $shippingMethod  = $quote->getShippingAddress()->getShippingMethod();

        if ($this->isMyParcelRelated($shippingMethod, $myParcelMethods)) {
            return true;
        }

        if ($this->isMyParcelRelated($shippingMethod, $this->parentMethods)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $input
     * @param array  $data
     *
     * @return bool
     */
    private function isMyParcelRelated(string $input, array $data): bool
    {
        $result = array_filter(
            $data,
            function ($item) use ($input) {
                if (stripos($input, $item) !== false) {
                    return true;
                }

                return false;
            }
        );

        return count($result) > 0;
    }
}
