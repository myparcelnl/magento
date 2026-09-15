<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Sales;

use Exception;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Status\History\Collection as OrderStatusHistoryCollection;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\Shipment as ShipmentResource;
use Magento\Shipping\Model\ShipmentNotifier;
use MyParcelNL\Magento\Cron\UpdateStatus;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Shipment\FulfilmentOrderBuilder;
use MyParcelNL\Magento\Service\LogContext;
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

    /** @var ShipmentResource\Collection|null loaded once; setOrderCollection() drops it */
    private $shipments = null;

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
        $this->orders    = $orderCollection;
        $this->shipments = null;
        $this->forgetTracks();

        return $this;
    }

    /**
     * Re-read the orders, after new Magento shipments were created for them.
     *
     * One filtered collection, not an Order::load() each: a page of fifty orders was fifty loads.
     *
     * @return $this
     */
    public function reload(): self
    {
        $orders = $this->objectManager->create(OrderResource\Collection::class);
        $orders->addFieldToFilter('entity_id', ['in' => $this->orders->getAllIds()]);

        return $this->setOrderCollection($orders);
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
        $tracks = $this->tracksByShipmentId();

        foreach ($this->getShipmentsCollection() as $shipment) {
            $i = 1;

            if (
                ! ($tracks[(int) $shipment->getId()] ?? []) ||
                $this->getOption('create_track_if_one_already_exist')
            ) {
                while ($i <= $this->getOption('label_amount')) {
                    $this->setNewMagentoTrack($shipment);
                    $i++;
                }
            }
        }

        return $this;
    }

    /**
     * Export every order in the collection as a PPS order, grouped by API key and chunked.
     *
     * The grouping looks redundant — OrderCollection::save() groups too — but its grouped calls are
     * one method: an exception on the second account escapes before the first account's orders are
     * marked exported, and the next run creates them again.
     *
     * @return $this
     */
    public function setFulfilment(): self
    {
        $builder   = new FulfilmentOrderBuilder($this->objectManager);
        $chunkSize = $this->config->getExportChunkSize();

        foreach ($this->ordersByApiKey() as $magentoOrders) {
            foreach (array_chunk($magentoOrders, $chunkSize) as $chunk) {
                $this->exportFulfilmentChunk($builder, $chunk);
            }
        }

        return $this;
    }

    /**
     * One request, whose orders are marked exported before the next request goes out.
     *
     * A chunk that fails is reported and skipped rather than abandoning the account: the remaining
     * chunks are still worth sending, the same way one order failing to build does not stop the
     * others in its chunk.
     *
     * Protected rather than private so the chunking above can be tested without a live API: every
     * path through this method ends in an HTTP call the SDK builds its own cURL handle for.
     *
     * @param Order[] $magentoOrders
     */
    protected function exportFulfilmentChunk(FulfilmentOrderBuilder $builder, array $magentoOrders): void
    {
        $orderCollection = (new OrderCollection())->setUserAgents($this->userAgent->map());
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
            return;
        }

        try {
            $savedOrders = $orderCollection->save();
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return;
        }

        // Before the next request is made: an order that exists in the API but carries no exported
        // status is created again by the next run, as a second billable order.
        $this->setMagentoOrdersAsExported($exported, $savedOrders);

        try {
            $this->saveOrderNotes($savedOrders);
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
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
                $apiKey = $this->apiProvider->apiKeyForStore((int) $magentoOrder->getStoreId());
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

    private function saveOrderNotes(OrderCollection $savedOrders): void
    {
        $incrementIds = [];

        $savedOrders->each(function (FulfilmentOrder $order) use (&$incrementIds) {
            $incrementIds[] = (string) $order->getExternalIdentifier();
        });

        $commentsByIncrementId = $this->orderCommentsByIncrementId($incrementIds);

        $savedOrders->each(function (FulfilmentOrder $order) use ($commentsByIncrementId) {

            // Only this one reaches the wire.
            $notes    = (new OrderNotesCollection())->setUserAgents($this->userAgent->map());
            $comments = $commentsByIncrementId[(string) $order->getExternalIdentifier()] ?? [];

            foreach ($comments as $comment) {
                $note = new OrderNote([
                    'orderUuid' => $order->getUuid(),
                    'note'      => $comment,
                    'author'    => 'webshop',
                ]);

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
            }

            $notes->save($order->getApiKey());
        });
    }

    /**
     * Every order's status-history comments, for the whole batch in two queries.
     *
     * Newest first, which is the order getStatusHistoryCollection() returns them in.
     *
     * @param  string[] $incrementIds
     * @return array<string,string[]> increment id => comments
     */
    protected function orderCommentsByIncrementId(array $incrementIds): array
    {
        if (! $incrementIds) {
            return [];
        }

        $orders = $this->objectManager->create(self::PATH_MODEL_ORDER_COLLECTION);
        $orders->addFieldToFilter('increment_id', ['in' => $incrementIds]);

        $incrementIdByEntityId = [];

        foreach ($orders as $magentoOrder) {
            $incrementIdByEntityId[(int) $magentoOrder->getEntityId()] = (string) $magentoOrder->getIncrementId();
        }

        if (! $incrementIdByEntityId) {
            return [];
        }

        $history = $this->objectManager->create(OrderStatusHistoryCollection::class);
        $history->addFieldToFilter('parent_id', ['in' => array_keys($incrementIdByEntityId)]);
        $history->setOrder('created_at', 'DESC');
        $history->setOrder('entity_id', 'DESC');

        $comments = [];

        foreach ($history as $status) {
            $comment = $status->getComment();

            if (! $comment) {
                continue;
            }

            $comments[$incrementIdByEntityId[(int) $status->getParentId()]][] = (string) $comment;
        }

        return $comments;
    }

    /**
     * @param Order[] $magentoOrders the orders this account's call actually carried
     */
    private function setMagentoOrdersAsExported(array $magentoOrders, OrderCollection $savedOrders): void
    {
        $byExternalIdentifier = [];

        foreach ($savedOrders as $savedOrder) {
            $byExternalIdentifier[(string) $savedOrder->getExternalIdentifier()] = $savedOrder;
        }

        foreach ($magentoOrders as $magentoOrder) {
            $magentoOrder->setData('track_status', UpdateStatus::ORDER_STATUS_EXPORTED);

            $fulfilmentOrder = $byExternalIdentifier[(string) $magentoOrder->getIncrementId()] ?? null;

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
        $tracks = $this->tracksByShipmentId();

        foreach ($this->shipmentsByIncrementId() as $incrementId => $magentoShipments) {
            $fulfilment = $fulfilmentByIncrementId[$incrementId] ?? null;

            if (null === $fulfilment) {
                continue;
            }

            $this->allocateTracks((string) $incrementId, $magentoShipments, $fulfilment, $tracks);
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
        $incrementIdByOrderId = [];

        foreach ($this->getOrders() as $order) {
            $incrementIdByOrderId[(int) $order->getId()] = (string) $order->getIncrementId();
        }

        $grouped = [];

        foreach ($this->getShipmentsCollection() as $shipment) {
            // getOrder() loads the order through the repository; the collection these shipments were
            // filtered by already holds every increment id. Fall back only for a shipment outside it.
            $incrementId = $incrementIdByOrderId[(int) $shipment->getOrderId()]
                ?? (string) $shipment->getOrder()->getIncrementId();

            $grouped[$incrementId][] = $shipment;
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
     * @param array<int,\Magento\Sales\Model\Order\Shipment\Track[]> $tracksByShipmentId the whole collection's
     */
    private function allocateTracks(
        string $incrementId,
        array $magentoShipments,
        array $fulfilmentShipments,
        array $tracksByShipmentId
    ): void {
        $claimedIds = [];
        $free       = [];
        $host       = null;

        foreach ($magentoShipments as $magentoShipment) {
            foreach ($tracksByShipmentId[(int) $magentoShipment->getId()] ?? [] as $magentoTrack) {
                if (Carrier::CODE !== $magentoTrack->getCarrierCode()) {
                    continue;
                }

                $host          = $host ?? $magentoShipment;
                $consignmentId = (int) $magentoTrack->getData('myparcel_consignment_id');

                if (0 !== $consignmentId) {
                    $claimedIds[$consignmentId] = true;
                    continue;
                }

                if (TrackAndTrace::isPlaceholder($magentoTrack->getTrackNumber())) {
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
                || TrackAndTrace::isRealBarcode($fulfilment['barcode']);

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
     * Loaded once per set of orders. No shipment row is created after setNewMagentoShipment(), and
     * tracks are read through tracksByShipmentId() rather than off these objects, so nothing here
     * goes stale within a run. setOrderCollection() — which reload() goes through — drops it.
     */
    protected function getShipmentsCollection(): ShipmentResource\Collection
    {
        if (null !== $this->shipments) {
            return $this->shipments;
        }

        $orderIds = [];
        foreach ($this->getOrders() as $order) {
            $orderIds[] = $order->getEntityId();
        }

        $shipmentsCollection = $this->objectManager->create(MagentoShipmentCollection::PATH_MODEL_SHIPMENT_COLLECTION);
        $shipmentsCollection->addAttributeToFilter('order_id', ['in' => $orderIds]);

        return $this->shipments = $shipmentsCollection;
    }

    /**
     * Only the orders this pass actually changed.
     *
     * AbstractDb::save() opens and commits a transaction before it notices an unmodified model, so
     * an untouched order still cost a round trip each way — two hundred of them on a mass action
     * that changed none.
     */
    private function save(): void
    {
        $resourceManager = $this->objectManager->get(OrderResource::class);

        foreach ($this->getOrders() as $order) {
            if (! $order->hasDataChanges()) {
                continue;
            }

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
