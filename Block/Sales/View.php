<?php
/**
 * Show MyParcel options in order detailpage
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

namespace MyParcelNL\Magento\Block\Sales;

use DateTime;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;
use MyParcelNL\Magento\Helper\Checkout as CheckoutHelper;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;

class View extends AbstractOrder
{
    /**
     * Collect options selected at checkout and calculate type consignment
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     */
    public function getCheckoutOptionsHtml(): string
    {
        $order = $this->getOrder();

        /** @var object $data Data from checkout */
        $data = $order->getData(CheckoutHelper::FIELD_DELIVERY_OPTIONS) !== null ? json_decode($order->getData(CheckoutHelper::FIELD_DELIVERY_OPTIONS), true) : null;

        if (! is_array($data)) {
            return '';
        }

        $date            = new DateTime($data['date'] ?? '');
        $dateTime        = $date->format('d-m-Y H:i');
        $deliveryOptions = DeliveryOptionsAdapterFactory::create((array) $data);

        ob_start();

        if ($deliveryOptions->isPickup()) {
            try {
                echo __("{$data['carrier']} location:"), ' ', $dateTime;

                if ($data['deliveryType'] !== 'pickup') {
                    echo ', ', __($data['deliveryType']);
                }

                $pickupLocation = $deliveryOptions->getPickupLocation();

                if (null !== $pickupLocation) {
                    echo ', ', $pickupLocation->getLocationName(), ', ', $pickupLocation->getCity(), ' (', $pickupLocation->getPostalCode(), ')';
                }

            } catch (\Throwable $e) {
                ObjectManager::getInstance()->get(CheckoutHelper::class)->log($e->getMessage());
                echo __('MyParcel options data not found');
            }
        } elseif (array_key_exists('date', $data)) {
            if (array_key_exists('packageType', $data)) {
                echo __($data['packageType']), ' ';
            }

            echo __('Deliver:'), ' ', $dateTime;

            $shipmentOptions = $deliveryOptions->getShipmentOptions();

            if (null !== $shipmentOptions) {
                if ($shipmentOptions->hasSignature()) {
                    echo ', ', __('Signature on receipt');
                }
                if ($shipmentOptions->hasOnlyRecipient()) {
                    echo ', ', __('Home address only');
                }
            }
        }

        return htmlentities(ob_get_clean());
    }
}
