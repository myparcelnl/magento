<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Plugin\Repository;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\MagentoAddressAdapter;
use MyParcelNL\Magento\Magento\Contract\MagentoOrderRepositoryInterface;
use MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface;
use MyParcelNL\Pdk\App\Order\Model\PdkOrder;
use MyParcelNL\Pdk\App\Order\Model\PdkOrderLine;
use MyParcelNL\Pdk\App\Order\Repository\AbstractPdkOrderRepository;
use MyParcelNL\Pdk\Base\Support\Collection;
use MyParcelNL\Pdk\Facade\Logger;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Pdk\Shipment\Collection\ShipmentCollection;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;
use Throwable;

class PdkOrderRepository extends AbstractPdkOrderRepository
{
    private $addressAdapter;

    /**
     * @var \MyParcelNL\Magento\Magento\Contract\MagentoOrderRepositoryInterface
     */
    private $magentoOrderRepository;

    /**
     * @var \MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface
     */
    private $pdkProductRepository;

    /**
     * @param  \MyParcelNL\Pdk\Storage\Contract\StorageInterface                    $storage
     * @param  \MyParcelNL\Magento\Magento\Contract\MagentoOrderRepositoryInterface $magentoOrderRepository
     * @param  \MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface     $pdkProductRepository
     * @param  \MyParcelNL\Magento\Adapter\MagentoAddressAdapter                    $addressAdapter
     */
    public function __construct(
        StorageInterface                $storage,
        MagentoOrderRepositoryInterface $magentoOrderRepository,
        PdkProductRepositoryInterface   $pdkProductRepository,
        MagentoAddressAdapter           $addressAdapter
    ) {
        parent::__construct($storage);
        $this->magentoOrderRepository = $magentoOrderRepository;
        $this->pdkProductRepository   = $pdkProductRepository;
        $this->addressAdapter         = $addressAdapter;
    }

    /**
     * @param $input
     *
     * @return \MyParcelNL\Pdk\App\Order\Model\PdkOrder
     */
    public function get($input): PdkOrder
    {
        $order = $this->magentoOrderRepository->get($input);

        return $this->retrieve((string) $order->getId(), function () use ($order) {
            try {
                return $this->getDataFromOrder($order);
            } catch (Throwable $exception) {
                Logger::error(
                    'Could not retrieve order data from Magento order',
                    [
                        'order_id' => $order->getId(),
                        'error'    => $exception->getMessage(),
                    ]
                );

                return new PdkOrder();
            }
        });
    }

    /**
     * @param  \MyParcelNL\Pdk\App\Order\Model\PdkOrder $order
     *
     * @return \MyParcelNL\Pdk\App\Order\Model\PdkOrder
     */
    public function update(PdkOrder $order): PdkOrder
    {
        // TODO: Implement update() method.
    }

    /**
     * @param  \Magento\Sales\Model\Order $order
     *
     * @return \MyParcelNL\Pdk\App\Order\Model\PdkOrder
     * @throws \MyParcelNL\Pdk\Base\Exception\InvalidCastException
     */
    private function getDataFromOrder(Order $order): PdkOrder
    {
        $savedOrderData = []; // TODO: get saved order data from storage
        $items          = $this->getOrderItems($order);

        $shippingAddress = $this->addressAdapter->fromMagentoOrder($order);

        $savedOrderData['deliveryOptions'] = []; //TODO: get delivery options from storage

        $orderData = [
            'externalIdentifier'    => $order->getId(),
            'referenceIdentifier'   => $order->getIncrementId(),
            'billingAddress'        => $this->addressAdapter->fromMagentoOrder(
                $order,
                Pdk::get('wcAddressTypeBilling')
            ),
            'lines'                 => $items
                ->map(function (array $item) {
                    /** @var \Magento\Sales\Model\Order\Item $magentoItem */
                    $magentoItem    = $item['item'];

                    return new PdkOrderLine([
                        'quantity' => (int) $magentoItem->getQtyOrdered(),
                        'price'    => (int) ((float) $magentoItem->getPrice() * 100),
                        'product'  => $item['pdkProduct'],
                    ]);
                })
                ->all(),
            'shippingAddress'       => $shippingAddress,
            'orderPrice'            => (float) $order->getGrandTotal(), // TODO: Find right amount for this
            'orderPriceAfterVat'    => (float) $order->getGrandTotal(),
            'orderVat'              => (float) $order->getTaxAmount(),
            'shipmentPrice'         => (float) $order->getShippingAmount(),
            'shipmentPriceAfterVat' => (float) $order->getShippingInclTax(),
            'shipments'             => $this->getShipments($order),
            'shipmentVat'           => (float) $order->getShippingTaxAmount(),
            'orderDate'             => $order->getCreatedAt(),
        ];

        return new PdkOrder(array_replace($orderData, $savedOrderData));
    }

    private function getOrderItems(Order $order): Collection
    {
        return $this->magentoOrderRepository->getItems($order)
            ->map(function (array $item) {
                return array_merge(
                    $item,
                    [
                        'pdkProduct' => $item['product']
                            ? $this->pdkProductRepository->getProduct($item['product'])
                            : null,
                    ]
                );
            });
    }

    /**
     * @param  \Magento\Sales\Model\Order $order
     *
     * @return \MyParcelNL\Pdk\Shipment\Collection\ShipmentCollection
     */
    private function getShipments(Order $order): ShipmentCollection
    {
        return $this->retrieve(
            "magento_order_shipments_{$order->getId()}",
            function () use ($order): ShipmentCollection {
                $shipments = []; // TODO: Get shipments from database

                return new ShipmentCollection($shipments);
            }
        );
    }
}
