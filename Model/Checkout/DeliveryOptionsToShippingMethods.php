<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Checkout;

use Exception;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;

class DeliveryOptionsToShippingMethods
{
    /**
     * @var \MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter
     */
    private $deliveryOptions;

    /**
     * @var string
     */
    private $shippingMethod;

    /**
     * DeliveryOptionsToShippingMethods constructor.
     *
     * @param array $deliveryOptions - Array created by the delivery options module in the checkout.
     *
     * @throws Exception
     */
    public function __construct(array $deliveryOptions)
    {
        $this->deliveryOptions = DeliveryOptionsAdapterFactory::create($deliveryOptions);
        $this->createShippingMethod();
        file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', "DeliveryOptionsToShippingMethods is called / constructed and probably not working\n", FILE_APPEND);
        throw new \Exception('DeliveryOptionsToShippingMethods is called, this is not working probably');
    }

    /**
     * Create a string representation of the delivery options for use with our custom shipping methods.
     *
     * @throws Exception
     */
    private function createShippingMethod(): void
    {
        $xmlPath               = $this->getCarrierXmlPath();
        $deliveryType          = $this->createDeliveryTypeString();
        $shipmentOptionsString = $this->createShipmentOptionsString();

        $this->shippingMethod = $xmlPath . $deliveryType . $shipmentOptionsString;
    }

    /**
     * Return the xml path of the settings associated with the carrier in the delivery options.
     *
     * @return string
     * @throws Exception
     */
    private function getCarrierXmlPath(): string
    {
        $carrier = $this->deliveryOptions->getCarrier();

        if (array_key_exists($carrier, Config::CARRIERS_XML_PATH_MAP)) {
            return Config::CARRIERS_XML_PATH_MAP[$carrier];
        }

        throw new Exception("No XML path found for carrier '{$carrier}'.");
    }

    /**
     * Return either "delivery" or "pickup" based on the delivery options.
     *
     * @return string
     */
    private function createDeliveryTypeString(): string
    {
        if ($this->deliveryOptions->isPickup()) {
            return 'pickup';
        }

        switch ($this->deliveryOptions->getPackageType()) {
            case 'mailbox':
                return 'mailbox';
            case 'digital_stamp':
                return 'digital_stamp';
        }

        switch ($this->deliveryOptions->getDeliveryType()) {
            case 'morning':
                return 'morning';
            case 'evening':
                return 'evening';
            default:
                return 'delivery';
        }
    }

    /**
     * Create a slash (/) delimited string from any shipment options, sorted alphabetically.
     * Returns empty string if there are no shipment options.
     *
     * @return string
     */
    private function createShipmentOptionsString(): string
    {
        // Filter out options that are not enabled.
        $shipmentOptions = array_filter(
            $this->deliveryOptions->getShipmentOptions()->toArray(),
            static function ($option) {
                return true === $option;
            }
        );

        // Sort the shipment option alphabetically by keys.
        ksort($shipmentOptions);

        if (count($shipmentOptions)) {
            $shipmentOptionsString = '/' . implode('/', array_keys($shipmentOptions) ?? []);
        }

        return $shipmentOptionsString ?? '';
    }

    /**
     * @return string
     */
    public function getShippingMethod(): string
    {
        return $this->shippingMethod;
    }
}
