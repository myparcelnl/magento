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
use MyParcelNL\Magento\Model\Rest\Resource\OrderDeliveryOptionsV1Resource;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;

class OrderDeliveryOptions extends AbstractEndpoint implements OrderDeliveryOptionsInterface
{
    private OrderRepositoryInterface $orderRepository;
    private OrderDeliveryOptionsV1Request $v1Request;

    public function __construct(
        Request $request,
        Response $response,
        VersionContext $versionContext,
        OrderRepositoryInterface $orderRepository,
        OrderDeliveryOptionsV1Request $v1Request
    ) {
        parent::__construct($request, $response, $versionContext);
        $this->orderRepository = $orderRepository;
        $this->v1Request       = $v1Request;
    }

    protected function getRequestHandlers(): array
    {
        return [1 => $this->v1Request];
    }

    protected function getResourceHandlers(): array
    {
        return [1 => OrderDeliveryOptionsV1Resource::class];
    }

    public function getByOrderId(int $orderId): string
    {
        try {
            /** @var OrderDeliveryOptionsV1Request $handler */
            $handler = $this->negotiate();

            if ($orderId <= 0) {
                return $this->errorResponse(ProblemDetails::fromStatus(
                    400,
                    'Request validation failed: orderId'
                ));
            }

            $order = $this->orderRepository->get($orderId);

            $deliveryOptionsJson = $order->getData(Config::FIELD_DELIVERY_OPTIONS);
            $data = $deliveryOptionsJson ? json_decode($deliveryOptionsJson, true) : null;

            if (empty($data) || !is_array($data)) {
                $resource = $this->createResource([
                    'carrier'         => null,
                    'packageType'     => null,
                    'deliveryType'    => null,
                    'shipmentOptions' => null,
                    'date'            => null,
                    'pickupLocation'  => null,
                ]);
            } else {
                $adapter  = DeliveryOptionsAdapterFactory::create($data);
                $resource = $this->createResource($handler->transform($adapter));
            }

            return json_encode($resource->format());
        } catch (WebapiException $e) {
            return $this->errorResponse(ProblemDetails::fromStatus(
                $e->getHttpCode(),
                $e->getMessage()
            ));
        } catch (NoSuchEntityException $e) {
            return $this->errorResponse(ProblemDetails::fromStatus(
                404,
                sprintf('Order with id %d was not found', $orderId)
            ));
        } catch (\Throwable $e) {
            Logger::error($e->getMessage());

            return $this->errorResponse(ProblemDetails::fromStatus(
                500,
                'An unexpected error occurred'
            ));
        }
    }
}
