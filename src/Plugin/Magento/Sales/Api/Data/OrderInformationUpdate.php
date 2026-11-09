<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Sales\Api\Data;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\LogContext;


/**
 * Adds the MyParcel delivery options as an extension attribute to orders from OrderRepository.
 *
 * Applies to get() and getList(). The attribute keeps the old serialized format for backward
 * compatibility; the REST delivery-options endpoint gives the new format.
 */
class OrderInformationUpdate
{

    const DELIVERY_OPTIONS = 'myparcel_delivery_options';

    /** @var OrderExtensionFactory */
    protected OrderExtensionFactory $extensionFactory;
    protected Json                  $jsonSerializer;

    public function __construct(OrderExtensionFactory $extensionFactory, Json $jsonSerializer)
    {
        $this->extensionFactory = $extensionFactory;
        $this->jsonSerializer = $jsonSerializer;
    }

    /**
     * Attach MyParcel delivery options as extension attributes to the order, if available.
     *
     * @param OrderInterface $order
     * @return void
     */
    private function addDeliveryOptionsToOrder(OrderInterface $order): void
    {
        $deliveryOptionsString = $order->getData(Config::FIELD_DELIVERY_OPTIONS);

        if (!$deliveryOptionsString) {
            // No delivery options data found for this order, legitimate: another shipping method was used.
            return;
        }

        try {
            /** @var object $data Data from checkout */
            $data = $this->jsonSerializer->unserialize($deliveryOptionsString);
            $deliveryOptions = DeliveryOptionsFactory::create((array) $data);
        } catch (\Exception $e) {
            Logger::warning(
                "Invalid delivery options data for order ID {$order->getEntityId()}.",
                LogContext::of($e, ['delivery_options' => LogContext::shapeOf($deliveryOptionsString)])
            );

            return;
        }

        $extensionAttributes = $order->getExtensionAttributes() ?: $this->extensionFactory->create();
        // the encode string is backwards compatible, use the rest delivery-options endpoint for the new format
        $extensionAttributes->setDeliveryOptions($this->jsonSerializer->serialize($deliveryOptions->toArray()));
        $order->setExtensionAttributes($extensionAttributes);
    }

    /**
     * Add "delivery_type" extension attribute to order data object to make it accessible in API data
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     *
     * @return OrderInterface
     */
    public function afterGet(OrderRepositoryInterface $subject, OrderInterface $order)
    {
        $this->addDeliveryOptionsToOrder($order);

        return $order;
    }

    /**
     * Add "delivery_type" extension attribute to order data object to make it accessible in API data
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderSearchResultInterface $searchResult
     *
     * @return OrderSearchResultInterface
     */
    public function afterGetList(OrderRepositoryInterface $subject, OrderSearchResultInterface $searchResult)
    {
        $orders = $searchResult->getItems();

        foreach ($orders as &$order) {
            $this->addDeliveryOptionsToOrder($order);
        }

        return $searchResult;
    }
}
