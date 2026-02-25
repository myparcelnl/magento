<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Api\OrderDeliveryOptionsInterface;
use MyParcelNL\Magento\Model\Rest\Request\OrderDeliveryOptionsV1Request;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;

class OrderDeliveryOptions implements OrderDeliveryOptionsInterface
{
    private OrderRepositoryInterface $orderRepository;
    private OrderDeliveryOptionsV1Request $v1Request;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderDeliveryOptionsV1Request $v1Request
    ) {
        $this->orderRepository = $orderRepository;
        $this->v1Request = $v1Request;
    }

    public function getByOrderId(int $orderId): string
    {
        $order = $this->orderRepository->get($orderId);

        $deliveryOptionsJson = $order->getData(Config::FIELD_DELIVERY_OPTIONS);
        $data = $deliveryOptionsJson ? json_decode($deliveryOptionsJson, true) : null;

        if (empty($data) || !is_array($data)) {
            return json_encode([
                'carrier'         => null,
                'packageType'     => null,
                'deliveryType'    => null,
                'shipmentOptions' => null,
                'date'            => null,
                'pickupLocation'  => null,
            ]);
        }

        $adapter = DeliveryOptionsAdapterFactory::create($data);

        return json_encode($this->v1Request->transform($adapter));
    }
}
