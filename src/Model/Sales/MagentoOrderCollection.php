<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Sales;

use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\Shipment as ShipmentResource;
use Magento\Shipping\Model\ShipmentNotifier;
use MyParcelNL\Magento\Cron\UpdateStatus;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Shipment\FulfilmentOrderBuilder;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderCollection;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderNotesCollection;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\Model\Fulfilment\OrderNote;
use MyParcelNL\Sdk\Support\Str;
use Throwable;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelNL\Magento\Model\Sales
 */
class MagentoOrderCollection extends MagentoCollection
{
    /**
     * @var null|OrderResource\Collection|Order[]
     */
    private $orders = null;

    /**
     * Get all Magento orders
     *
     * @return OrderResource\Collection|Order[]
     */
    public function getOrders()
    {
        return $this->orders;
    }

    /**
     * Set Magento collection
     *
     * @param OrderResource\Collection|Order[] $orderCollection
     *
     * @return $this
     */
    public function setOrderCollection($orderCollection): self
    {
        $this->orders = $orderCollection;

        return $this;
    }

    /**
     * Set Magento collection
     *
     * @return $this
     */
    public function reload(): self
    {
        $ids = $this->orders->getAllIds();

        $orders = [];
        foreach ($ids as $orderId) {
            $objectManager = ObjectManager::getInstance();
            $orders[]      = $objectManager->create(Order::class)->load($orderId);
        }

        $this->setOrderCollection($orders);

        return $this;
    }

    /**
     * Set existing or create new Magento Track and set API consignment to collection
     *
     * @throws Exception
     * @throws LocalizedException
     */
    public function setNewMagentoShipment(bool $notifyClientsByEmail = true): MagentoOrderCollection
    {
        /** @var Order $order */
        foreach ($this->getOrders() as $order) {
            if ($order->canShip() && $this->createMagentoShipment($order, $notifyClientsByEmail)) {
                $order->setIsInProcess(true);
            }
        }

        $this->save();

        return $this;
    }

    /**
     * Create new Magento Track and save order
     *
     * @return $this
     * @throws Exception
     */
    public function setMagentoTrack(): MagentoOrderCollection
    {
        /**
         * @var Shipment $shipment
         */
        foreach ($this->getShipmentsCollection() as $shipment) {
            $i = 1;

            if (
                $this->shipmentHasTrack($shipment) === false ||
                $this->getOption('create_track_if_one_already_exist')
            ) {
                while ($i <= $this->getOption('label_amount')) {
                    $this->setNewMagentoTrack($shipment);
                    $i++;
                }
            }
        }

        $this->save();

        return $this;
    }

    /**
     * Export every order in the collection as a PPS order, one call per API key.
     *
     * The grouping looks redundant — OrderCollection::save() groups too — but its grouped calls are
     * one method: an exception on the second account escapes before the first account's orders are
     * marked exported, and the next run creates them again.
     *
     * @return $this
     */
    public function setFulfilment(): self
    {
        $builder = new FulfilmentOrderBuilder($this->objectManager);

        foreach ($this->ordersByApiKey() as $magentoOrders) {
            $orderCollection = (new OrderCollection())->setUserAgents($this->userAgent()->map());
            $exported        = [];

            foreach ($magentoOrders as $magentoOrder) {
                try {
                    $orderCollection->push($builder->build($magentoOrder, $this->options));
                    $exported[] = $magentoOrder;
                } catch (Throwable $e) {
                    $this->messageManager->addErrorMessage(
                        sprintf('%s: %s', $magentoOrder->getIncrementId(), $e->getMessage())
                    );
                }
            }

            if ($orderCollection->isEmpty()) {
                continue;
            }

            try {
                $savedOrders = $orderCollection->save();
            } catch (Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                continue;
            }

            // Before the next account is called: an order that exists in the API but carries no
            // exported status is created again by the next run, as a second billable order.
            $this->setMagentoOrdersAsExported($exported, $savedOrders);

            try {
                $this->saveOrderNotes($savedOrders);
            } catch (Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        return $this;
    }

    /**
     * The orders grouped by their own store's API key. A store with no key is reported and skipped,
     * never lent another store's key.
     *
     * @return array<string,Order[]>
     */
    private function ordersByApiKey(): array
    {
        $grouped = [];

        foreach ($this->getOrders() as $magentoOrder) {
            try {
                $apiKey = $this->apiProvider()->apiKeyForStore((int) $magentoOrder->getStoreId());
            } catch (Throwable $e) {
                $this->messageManager->addErrorMessage(
                    sprintf('%s: %s', $magentoOrder->getIncrementId(), $e->getMessage())
                );
                continue;
            }

            $grouped[$apiKey][] = $magentoOrder;
        }

        return $grouped;
    }

    private function apiProvider(): ShipmentApiProvider
    {
        return $this->objectManager->get(ShipmentApiProvider::class);
    }

    private function userAgent(): UserAgent
    {
        return $this->objectManager->get(UserAgent::class);
    }

    private function saveOrderNotes(OrderCollection $savedOrders): void
    {
        $savedOrders->each(function (FulfilmentOrder $order) {

            // Only this one reaches the wire; getAllNotesForOrder()'s collection is iterated into it.
            $notes = (new OrderNotesCollection())->setUserAgents($this->userAgent()->map());

            $this->getAllNotesForOrder($order)->each(function (OrderNote $note) use ($notes) {
                try {
                    $note->validate();
                    $notes->push($note);
                } catch (Throwable $e) {
                    $this->messageManager->addWarningMessage(
                        sprintf(
                            'Note `%s` not exported. %s',
                            Str::limit($note->getNote(), 30),
                            $e->getMessage()
                        )
                    );
                }
            });

            $notes->save($order->getApiKey());
        });
    }

    private function getAllNotesForOrder(FulfilmentOrder $fulfilmentOrder): OrderNotesCollection
    {
        $notes        = new OrderNotesCollection();
        $orderUuid    = $fulfilmentOrder->getUuid();
        $magentoOrder = $this->objectManager->create(Order::class)
                                            ->loadByIncrementId($fulfilmentOrder->getExternalIdentifier())
        ;

        foreach ($magentoOrder->getStatusHistoryCollection() as $status) {
            if (! $status->getComment()) {
                continue;
            }

            $notes->push(
                new OrderNote(
                    [
                        'orderUuid' => $orderUuid,
                        'note'      => $status->getComment(),
                        'author'    => 'webshop',
                    ]
                )
            );
        }

        return $notes;
    }

    /**
     * @param Order[] $magentoOrders the orders this account's call actually carried
     */
    private function setMagentoOrdersAsExported(array $magentoOrders, OrderCollection $savedOrders): void
    {
        foreach ($magentoOrders as $magentoOrder) {
            $magentoOrder->setData('track_status', UpdateStatus::ORDER_STATUS_EXPORTED);

            $fulfilmentOrder = $savedOrders->first(function (FulfilmentOrder $order) use ($magentoOrder) {
                return $order->getExternalIdentifier() === $magentoOrder->getIncrementId();
            });

            if ($fulfilmentOrder) {
                $magentoOrder->setData('myparcel_uuid', $fulfilmentOrder->getUuid());
            }

            $magentoOrder->setIsInProcess(true);
            $this->objectManager->get(OrderResource::class)->save($magentoOrder);
        }
    }

    /**
     * Give every shipped fulfilment shipment its own Magento track: the barcode, and the MyParcel
     * shipment id when the response carries one.
     *
     * The shipment path gets both from updateMagentoTrack(), which refreshes by
     * myparcel_consignment_id. A PPS order has no id until this runs, so without it the track keeps
     * its placeholder and never joins the status or track & trace refresh at all.
     *
     * The id is read defensively: it is not in the SDK's model — order_shipments passes through as
     * a raw array — so an absent one is logged, because it is the one case where the status and the
     * link can never arrive.
     *
     * @param array<string,array<int,array{barcode: string, shipmentId: int|null}>> $fulfilmentByIncrementId
     */
    public function setFulfilmentTrackData(array $fulfilmentByIncrementId): self
    {
        foreach ($this->shipmentsByIncrementId() as $incrementId => $magentoShipments) {
            $fulfilment = $fulfilmentByIncrementId[$incrementId] ?? null;

            if (null === $fulfilment) {
                continue;
            }

            $this->allocateTracks((string) $incrementId, $magentoShipments, $fulfilment);
        }

        return $this;
    }

    /**
     * The collection's Magento shipments grouped by their order's increment id.
     *
     * Allocation is per order, never per Magento shipment. An order shipped partly by hand before
     * PPS ran has two Magento shipments, and looking the fulfilment data up once per shipment wrote
     * the same barcode and the same shipment id onto a placeholder track of each.
     *
     * @return array<string,Shipment[]>
     */
    private function shipmentsByIncrementId(): array
    {
        $grouped = [];

        foreach ($this->getShipmentsCollection() as $shipment) {
            $grouped[(string) $shipment->getOrder()->getIncrementId()][] = $shipment;
        }

        return $grouped;
    }

    /**
     * One track per fulfilment shipment, for one Magento order.
     *
     * Three rules, in order: a shipment already on a track is left alone, so a re-run writes
     * nothing; otherwise it takes a placeholder track nothing has claimed; and only if none is free
     * does it get a new one. That last step needs an id or a real barcode — a shipment carrying
     * neither can never be recognised again, so it would earn another row on every run.
     *
     * @param Shipment[]                                              $magentoShipments all of one order's
     * @param array<int,array{barcode: string, shipmentId: int|null}> $fulfilmentShipments
     */
    private function allocateTracks(string $incrementId, array $magentoShipments, array $fulfilmentShipments): void
    {
        $claimedIds = [];
        $free       = [];
        $host       = null;

        foreach ($magentoShipments as $magentoShipment) {
            foreach ($this->getTrackByShipment($magentoShipment)->getItems() as $magentoTrack) {
                if (Carrier::CODE !== $magentoTrack->getCarrierCode()) {
                    continue;
                }

                $host          = $host ?? $magentoShipment;
                $consignmentId = (int) $magentoTrack->getData('myparcel_consignment_id');

                if (0 !== $consignmentId) {
                    $claimedIds[$consignmentId] = true;
                    continue;
                }

                if (in_array($magentoTrack->getTrackNumber(), TrackAndTrace::PLACEHOLDERS, true)) {
                    $free[] = $magentoTrack;
                }
            }
        }

        $host    = $host ?? end($magentoShipments);
        $written = 0;

        foreach ($fulfilmentShipments as $fulfilment) {
            $shipmentId = $fulfilment['shipmentId'];

            if (null !== $shipmentId && isset($claimedIds[$shipmentId])) {
                continue;
            }

            $hasIdentity = null !== $shipmentId
                || ! in_array($fulfilment['barcode'], TrackAndTrace::PLACEHOLDERS, true);

            if (! $free && ! $hasIdentity) {
                continue;
            }

            $magentoTrack = $free ? array_shift($free) : $this->setNewMagentoTrack($host);

            $magentoTrack->setTrackNumber($fulfilment['barcode']);

            if (null !== $shipmentId) {
                $magentoTrack->setData('myparcel_consignment_id', $shipmentId);
                $claimedIds[$shipmentId] = true;
            } else {
                // Without the id updateMagentoTrack() can never refresh this track, so it keeps
                // this barcode and gets no status and no consumer portal link, ever.
                Logger::warning(sprintf(
                    'MyParcel: fulfilment order %s came back with no shipment id for barcode %s, so its track gets no status or track & trace link',
                    $incrementId,
                    $fulfilment['barcode']
                ));
            }

            $magentoTrack->save();
            $written++;
        }

        if (1 < $written) {
            Logger::notice(sprintf(
                'MyParcel: order %s carries %d shipments, each on its own track',
                $incrementId,
                $written
            ));
        }
    }

    /**
     * Check if there is 1 shipment in all orders
     *
     * @return bool
     */
    public function hasShipment(): bool
    {
        foreach ($this->getOrders() as $order) {
            if ($order->hasShipments()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ShipmentResource\Collection
     */
    /**
     * Fresh every call, deliberately. A shared instance is loaded once and its Shipment objects
     * cache their own tracks, so updateMagentoTrack() would read the tracks as they were before
     * setFulfilmentTrackData() wrote the barcode and shipment id to them — and since a track with
     * a barcode leaves the cron's scope, a link missed on that pass is missed for good.
     * It also stopped the same order_id filter being stacked on one collection four times a run.
     */
    protected function getShipmentsCollection(): ShipmentResource\Collection
    {
        $orderIds = [];
        foreach ($this->getOrders() as $order) {
            $orderIds[] = $order->getEntityId();
        }

        $shipmentsCollection = $this->objectManager->create(MagentoShipmentCollection::PATH_MODEL_SHIPMENT_COLLECTION);
        $shipmentsCollection->addAttributeToFilter('order_id', ['in' => $orderIds]);

        return $shipmentsCollection;
    }

    /**
     * return void
     */
    private function save(): void
    {
        $resourceManager = $this->objectManager->get(OrderResource::class);

        foreach ($this->getOrders() as $order) {
            $resourceManager->save($order);
        }
    }

    /**
     * @throws AlreadyExistsException|LocalizedException
     */
    public function createMagentoShipment(Order $order, bool $notifyClientByEmail = true): bool
    {
        $convertOrder = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
        /**
         * @var Shipment $shipment
         */
        $shipment = $convertOrder->toShipment($order);

        $shipmentAttributes = $shipment->getExtensionAttributes();

        if ($shipmentAttributes && method_exists($shipmentAttributes, 'setSourceCode')) {
            $shipmentAttributes->setSourceCode($this->sourceItem->getSource($order, $order->getAllItems()));
            $shipment->setExtensionAttributes($shipmentAttributes);
        }

        foreach ($order->getAllItems() as $orderItem) {
            if (! $orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $qtyShipped   = $orderItem->getQtyToShip();
            $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $shipment->register(); // here the items are set to shipped in table sales_order_item

        try {
            $this->objectManager->get(ShipmentResource::class)->save($shipment);
        } catch (Throwable $e) {
            if (preg_match('/' . self::DEFAULT_ERROR_ORDER_HAS_NO_SOURCE . '/', $e->getMessage())) {
                $this->messageManager->addErrorMessage(__(self::ERROR_ORDER_HAS_NO_SOURCE));
            } else {
                $this->messageManager->addErrorMessage(__($e->getMessage()));
            }

            /**
             * Prevent not being able to ship an order even though no shipment was saved here:
             * undo the set shipment quantity update that $shipment->register did before the exception
             */
            foreach ($shipment->getAllItems() as $item) {
                $orderItem = $item->getOrderItem();
                $orderItem->setQtyShipped($orderItem->getQtyShipped() - $item->getQty());
            }
            $this->objectManager->get(OrderResource::class)->save($shipment->getOrder());

            Logger::critical('MyParcel: the Magento shipment could not be saved', LogContext::of($e));

            return false; // well that didn’t work
        }

        if ($notifyClientByEmail) {
            $this->objectManager->create(ShipmentNotifier::class)
                                ->notify($shipment)
            ;
        }

        return true;
    }
}
