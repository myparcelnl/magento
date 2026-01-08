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


class OrderInformationUpdate
{

    const DELIVERY_OPTIONS = 'myparcel_delivery_options';

    /**
     * Order Extension Attributes Factory
     *
     * @var OrderExtensionFactory
     */
    protected            OrderExtensionFactory                                $extensionFactory;
    protected Json $jsonSerializer;

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
     * Add "delivery_type" extension attribute to order data object to make it accessible in API data
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     *
     * @return OrderInterface
     */
    public function afterGet(OrderRepositoryInterface $subject, OrderInterface $order)
    {
        /** @var object $data Data from checkout */
        $data = $this->jsonSerializer->unserialize($order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? null, true);

        if (!is_array($data)) {
            return $order;
        }

        $deliveryOptions = DeliveryOptionsAdapterFactory::create((array) $data);
        $extensionAttributes = $order->getExtensionAttributes() ?: $this->extensionFactory->create();
        // the encode string is backwards compatible, use the rest delivery-options endpoint for the new format
        $extensionAttributes->setDeliveryOptions($this->jsonSerializer->serialize($deliveryOptions->toArray()));
        $order->setExtensionAttributes($extensionAttributes);

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
            /** @var object $data Data from checkout */
            $data = $this->jsonSerializer->unserialize($order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? null, true);

            if (!is_array($data)) {
                continue;
            }

            $deliveryOptions = DeliveryOptionsAdapterFactory::create((array) $data);
            $extensionAttributes = $order->getExtensionAttributes() ?: $this->extensionFactory->create();
            $extensionAttributes->setMyParcelDeliveryOptions($deliveryOptions->toArray());
            $order->setExtensionAttributes($extensionAttributes);
        }

        return $searchResult;
    }
}
