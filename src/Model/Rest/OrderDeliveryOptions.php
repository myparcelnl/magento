<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Api\OrderDeliveryOptionsInterface;
use MyParcelNL\Magento\Facade\Logger;
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
        try {
            /** @var OrderDeliveryOptionsV1Request $handler */
            $handler = $this->resolveVersion();

            if ($orderId <= 0) {
                return $this->errorResponse(new ProblemDetails(
                    null,
                    400,
                    'Invalid Request',
                    'Request validation failed: orderId'
                ));
            }

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
        } catch (WebapiException $e) {
            return $this->errorResponse(new ProblemDetails(
                null,
                $e->getHttpCode(),
                ProblemDetails::titleForStatus($e->getHttpCode()),
                $e->getMessage()
            ));
        } catch (NoSuchEntityException $e) {
            return $this->errorResponse(new ProblemDetails(
                null,
                404,
                'Order Not Found',
                sprintf('Order with id %d was not found', $orderId)
            ));
        } catch (\Throwable $e) {
            Logger::error($e->getMessage());

            return $this->errorResponse(new ProblemDetails(
                null,
                500,
                'Internal Server Error',
                'An unexpected error occurred'
            ));
        }
    }
}
