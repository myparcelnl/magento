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


use Magento\Framework\Event\ObserverInterface;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;

class SaveOrderBeforeSalesModelQuoteObserver implements ObserverInterface
{
    const DELIVERY_OPTIONS_FIELD = 'delivery_options';
    const DROP_OFF_DAY_FIELD = 'drop_off_day';
    /**
     * @var DeliveryRepository
     */
    private $delivery;

    /**
     * SaveOrderBeforeSalesModelQuoteObserver constructor.
     * @param DeliveryRepository $delivery
     */
    public function __construct(DeliveryRepository $delivery)
    {
        $this->delivery = $delivery;
    }

    /**
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /* @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getData('quote');
        /* @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getData('order');

        if ($quote->hasData(self::DELIVERY_OPTIONS_FIELD)) {
            $jsonDeliveryOptions = $quote->getData(self::DELIVERY_OPTIONS_FIELD);
            $order->setData(self::DELIVERY_OPTIONS_FIELD, $jsonDeliveryOptions);

            $dropOffDay = $this->delivery->getDropOffDayFromJson($jsonDeliveryOptions);
            $order->setData(self::DROP_OFF_DAY_FIELD, $dropOffDay);
        }

        return $this;
    }
}
