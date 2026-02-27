<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Sales\Api\Data;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;


/**
 * Plugin for Magento Sales OrderRepository to add MyParcel delivery options as extension attributes.
 *
 * This plugin ensures that MyParcel delivery options, stored as serialized data in the order's custom field,
 * are exposed as extension attributes on Order and OrderSearchResult objects returned by the API.
 *
 * Intended use:
 * - Allows external systems and API consumers to access MyParcel delivery options via the order extension attributes.
 * - Uses the old serialized format for backward compatibility, for modern API responses use the rest delivery-options endpoint which provides the new format.
 *
 * How it works:
 * - After an order is loaded via OrderRepository::get(), the plugin unserializes the MyParcel delivery options
 *   from the order data, creates a DeliveryOptionsAdapter, and attaches the serialized options to the order's
 *   extension attributes.
 * - After a list of orders is loaded via OrderRepository::getList(), the plugin performs the same process for
 *   each order in the result, attaching the delivery options as an array to the extension attributes.
 * - This makes the delivery options available in API responses and for further processing in Magento.
 */
class OrderInformationUpdate
{

    const DELIVERY_OPTIONS = 'myparcel_delivery_options';

    /**
     * Order Extension Attributes Factory
     *
     * @var OrderExtensionFactory
     */
    protected OrderExtensionFactory $extensionFactory;
    protected Json                  $jsonSerializer;

    /**
     * OrderRepositoryPlugin constructor
     *
     * @param OrderExtensionFactory $extensionFactory
     * @param Json                  $jsonSerializer
     */
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
        /** @var object $data Data from checkout */
        $data = $this->jsonSerializer->unserialize($order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? null, true);

        if (!is_array($data)) {
            return;
        }

        $deliveryOptions = DeliveryOptionsAdapterFactory::create((array) $data);
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
        $this->addDeliveryOptionsToOrder($order, true);

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
            $this->addDeliveryOptionsToOrder($order, false);
        }

        return $searchResult;
    }
}
