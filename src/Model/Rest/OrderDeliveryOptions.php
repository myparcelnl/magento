<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Api\OrderDeliveryOptionsInterface;
use MyParcelNL\Magento\Model\Rest\Request\OrderDeliveryOptionsV1Request;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;

class OrderDeliveryOptions extends AbstractEndpoint implements OrderDeliveryOptionsInterface
{
    private OrderRepositoryInterface $orderRepository;
    private OrderDeliveryOptionsV1Request $v1Request;

    public function __construct(
        Request $request,
        Response $response,
        OrderRepositoryInterface $orderRepository,
        OrderDeliveryOptionsV1Request $v1Request
    ) {
        parent::__construct($request, $response);
        $this->orderRepository = $orderRepository;
        $this->v1Request       = $v1Request;
    }

    protected function getVersionHandlers(): array
    {
        return [1 => $this->v1Request];
    }

    public function getByOrderId(int $orderId): string
    {
        /** @var OrderDeliveryOptionsV1Request $handler */
        $handler = $this->resolveVersion();

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

        return json_encode($handler->transform($adapter));
    }
}
