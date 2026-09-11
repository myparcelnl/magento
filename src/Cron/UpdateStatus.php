<?php

declare(strict_types=1);
/**
 * Update MyParcel data
 * Trigger actions:
 * - Update status in Track
 * - Update barcode in Track
 * - Update html status in order
 * - Update html barcode in order
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Cron;

use DateTime;
use Exception;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection;
use MyParcelNL\Magento\Api\ShipmentStatus;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderCollection;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\Exception\AccountNotActiveException;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use Throwable;

class UpdateStatus
{
    public const ORDER_ID_NOT_TO_PROCESS = '000000000';
    public const ORDER_STATUS_EXPORTED = 'Exported';
    public const PATH_MODEL_ORDER_TRACK = Collection::class;

    /** Matches addOrdersToCollection()'s window: an order it will not load is not worth polling. */
    private const POLL_WINDOW_DAYS = 14;

    private const PAGE_SIZE = 300;


    private ObjectManagerInterface $objectManager;
    private \Magento\Sales\Model\ResourceModel\Order $orderResource;
    private MagentoOrderCollection $orderCollection;
    private Config                 $config;

    /**
     * UpdateStatus constructor.
     *
     * @param AreaList $areaList
     * @param \Magento\Sales\Model\ResourceModel\Order $orderResource
     *
     * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
     */
    public function __construct(
        AreaList                                 $areaList,
        \Magento\Sales\Model\ResourceModel\Order $orderResource
    )
    {
        $this->objectManager   = $objectManager = ObjectManager::getInstance();
        $this->config          = $objectManager->get(Config::class);
        $this->orderCollection = new MagentoOrderCollection($this->objectManager, null, $areaList);
        $this->orderResource   = $orderResource;
    }

    /**
     * Run the cron job
     *
     * @return $this
     * @throws LocalizedException
     * @throws Exception
     */
    public function execute(): self
    {
        if (Config::EXPORT_MODE_PPS === $this->config->getExportMode()) {
            return $this->updateStatusPPS();
        }
        return $this->updateStatusShipments();
    }

    /**
     * Handles orders exported using Orderbeheer (PPS) setting.
     * Gets the eligible orders from Magento, gets the account's orders from the api. When the api
     * order is one of them and it has shipped, adds the shipment in Magento.
     *
     * A fulfilment order can hold several shipments, and they are all created in one go: either as
     * separate shipments side by side, which is one order_shipments entry each, or as one multicollo,
     * which is one entry carrying secondary_shipments. This method handles the first shape by reading
     * every entry; the second is expanded from the shipment API by updateMagentoTrack(), because the
     * fulfilment response is not where a collo's own barcode is read.
     *
     * Each shipment gets its own track. There is no per-shipment item split to make a second Magento
     * shipment out of — the colli hold the same goods.
     *
     * NOTE: the 'reference_identifier' as returned by the api in the order object is the increment_id of the order,
     * the order_id in the track table however is the entity_id, these are not necessarily the same (number).
     *
     * Every shipped order goes through the shipment routine, and what that could not do is what the
     * fallback is for: an order left without a MyParcel track — no msi-source, or no shipment
     * Magento would make — gets its barcode written to the order table instead. Decided by outcome,
     * not by canShip(): createMagentoShipment() rolls its quantity change back on failure, so
     * canShip() stays true and the old "it cannot be shipped anymore next run" recovery never
     * happened.
     *
     * A trackless order can carry a barcode but never a link: myparcel_tracktrace_url is a column
     * on sales_shipment_track.
     *
     * @return $this
     * @throws AlreadyExistsException
     * @throws AccountNotActiveException
     * @throws ApiException
     * @throws MissingFieldException
     * @throws Exception
     */
    private function updateStatusPPS(): self
    {
        $orderRows    = $this->ordersAwaitingBarcode();
        $orderIdsDone = [];
        $fulfilment   = [];
        // One line at the end beats guessing which of five steps dropped an order.
        $tally = ['awaiting' => count($orderRows), 'accounts' => 0, 'returned' => 0, 'matched' => 0,
                  'shipped'  => 0, 'shipments' => 0, 'without_id' => 0, 'order_row' => 0];

        foreach ($this->incrementIdsByApiKey($orderRows) as $apiKey => $orderIdsToCheck) {
            try {
                $apiOrders = $this->queryApiOrders($apiKey);
            } catch (Throwable $e) {
                // One unreachable account must not cost the other accounts their update.
                Logger::warning('PPS: could not poll one account', LogContext::of($e));
                continue;
            }

            $tally['accounts']++;
            $tally['returned'] += count($apiOrders);

            foreach ($apiOrders->getIterator() as $apiOrder) {
                $incrementId = $apiOrder->getExternalIdentifier();

                if (! $incrementId
                    || isset($orderIdsDone[$incrementId])
                    || ! in_array($incrementId, $orderIdsToCheck, true)) {
                    continue;
                }

                $tally['matched']++;
                $shipments = $this->shippedShipments($apiOrder);

                // Recorded only once it really shipped. Marked before the check, an order still a
                // concept in the backoffice reached the collection below and was given a Magento
                // shipment anyway.
                if (! $shipments) {
                    continue;
                }

                $tally['shipped']++;
                $tally['shipments'] += count($shipments);

                foreach ($shipments as $shipment) {
                    if (null === $shipment['shipmentId']) {
                        $tally['without_id']++;
                    }
                }

                $orderIdsDone[$incrementId] = $incrementId;
                $fulfilment[$incrementId]   = $shipments;
            }
        }

        // Debug, not notice: this runs every minute and says nothing happened most of the time.
        // What is worth a place in system.log is below, and only when something actually did.
        Logger::debug(sprintf(
            'PPS: %d order(s) awaiting a barcode over %d account(s); the API returned %d, of which '
            . '%d matched and %d shipped, carrying %d shipment(s)',
            $tally['awaiting'],
            $tally['accounts'],
            $tally['returned'],
            $tally['matched'],
            $tally['shipped'],
            $tally['shipments']
        ));

        if (! $orderIdsDone) {
            return $this;
        }

        $orderIncrementIds = array_unique(array_values($orderIdsDone));
        $orderEntityIds    = [];

        foreach ($orderRows as $arrayWithIds) {
            if (! in_array($arrayWithIds['increment_id'], $orderIncrementIds)) {
                continue;
            }
            $orderEntityIds[] = $arrayWithIds['entity_id'];
        }

        if (! $orderEntityIds) {
            return $this;
        }

        Logger::notice(sprintf('PPS: update orders %s', implode(', ', $orderIncrementIds ?? [])));
        $this->addOrdersToCollection($orderEntityIds);

        // setNewMyParcelTracks() is deliberately absent: it builds a v11 Shipment per track for a
        // create this cron never issues. What a PPS order needs is the id and barcode its
        // fulfilment order already has, after which updateMagentoTrack() refreshes it like any
        // other shipment — status and track & trace link included.
        //
        // An order that already has a track reaches setMagentoTrack() now, so the mass-action
        // default that adds one anyway has to go, or every run would add another.
        $this->orderCollection->setOption('create_track_if_one_already_exist', false)
                              ->setNewMagentoShipment(false)
                              ->setMagentoTrack()
                              ->setFulfilmentTrackData($fulfilment)
                              ->updateMagentoTrack();

        $tally['order_row'] = $this->writeBarcodeWhereNoTrack($fulfilment);

        if ($tally['order_row'] || $tally['without_id']) {
            Logger::notice(sprintf(
                'PPS: %d order(s) got their barcode on the order row for want of a track; '
                . '%d shipment(s) came back with no id, so they get no status and no link',
                $tally['order_row'],
                $tally['without_id']
            ));
        }

        return $this;
    }

    /**
     * The PPS orders still waiting for a barcode.
     *
     * Keyed on the export marker and on the absence of a real barcode — not on
     * sales_order.track_number being null. That column is a grid display value, and anything
     * written to it, a placeholder included, used to lock an order out of this cron for good
     *. A barcode can live in two places: on a track, or on the order itself for an order
     * Magento could not ship.
     *
     * @return array[] sales_order rows carrying increment_id, entity_id and store_id
     */
    protected function ordersAwaitingBarcode(): array
    {
        /** @var \Magento\Sales\Model\ResourceModel\Order\Collection $orders */
        $orders = $this->objectManager->create(MagentoCollection::PATH_MODEL_ORDER_COLLECTION);
        $since  = (new DateTime(sprintf('now -%d day', self::POLL_WINDOW_DAYS)))->format('Y-m-d H:i:s');

        $orders
            ->addFieldToSelect('increment_id')
            ->addFieldToSelect('entity_id')
            ->addFieldToSelect('store_id')
            ->addFieldToFilter('myparcel_uuid', ['notnull' => true])
            ->addFieldToFilter('created_at', ['gteq' => $since])
            ->setPageSize(self::PAGE_SIZE)
            ->setOrder('entity_id', 'DESC');

        $connection = $orders->getConnection();

        $track = $orders->getTable('sales_shipment_track');

        $withRealBarcode = $connection->select()
            ->from(['t' => $track], [new \Zend_Db_Expr('1')])
            ->where('t.order_id = main_table.entity_id')
            ->where('t.carrier_code = ?', Carrier::CODE)
            ->where('t.track_number IS NOT NULL')
            ->where('t.track_number NOT IN (?)', TrackAndTrace::PLACEHOLDERS);

        $withAnyTrack = $connection->select()
            ->from(['a' => $track], [new \Zend_Db_Expr('1')])
            ->where('a.order_id = main_table.entity_id')
            ->where('a.carrier_code = ?', Carrier::CODE);

        $placeholderOnOrder = array_map(
            static function (string $placeholder): string {
                return json_encode([$placeholder]);
            },
            TrackAndTrace::PLACEHOLDERS
        );

        // Done means a real barcode on a track. The order row only settles it for an order that has
        // no track at all — a barcode there is the fallback's, and it used to mask a track still
        // holding a placeholder.
        $orders->getSelect()
               ->where(sprintf('NOT EXISTS (%s)', $withRealBarcode))
               ->where(
                   sprintf(
                       'EXISTS (%s) OR main_table.track_number IS NULL OR main_table.track_number IN (?)',
                       $withAnyTrack
                   ),
                   $placeholderOnOrder
               );

        return $orders->getData();
    }

    /**
     * Every shipped shipment of one fulfilment order, in the order the API listed them.
     *
     * order_shipments passes through the SDK as a raw array with no model behind it, so each key is
     * read defensively. The shipped check is per entry: a fulfilment order can hold a concept or a
     * cancelled shipment beside shipped ones, and reading only the first entry is what hid an order
     * whose second shipment had shipped while its first was still a concept.
     *
     * @return array<int,array{barcode: string, shipmentId: int|null}>
     */
    private function shippedShipments(FulfilmentOrder $apiOrder): array
    {
        $shipments = [];
        $seenIds   = [];

        foreach ($apiOrder->getOrderShipments() as $orderShipment) {
            $shipment = is_array($orderShipment) ? ($orderShipment['shipment'] ?? null) : null;

            if (! is_array($shipment) || ! $this->apiShipmentIsShipped($shipment)) {
                continue;
            }

            $shipmentId = isset($shipment['id']) ? (int) $shipment['id'] : null;

            // A repeated id in one response would otherwise earn a second track.
            if (null !== $shipmentId) {
                if (isset($seenIds[$shipmentId])) {
                    continue;
                }

                $seenIds[$shipmentId] = true;
            }

            $shipments[] = [
                'barcode'    => (string) ($shipment['external_identifier'] ?? TrackAndTrace::VALUE_PRINTED),
                'shipmentId' => $shipmentId,
            ];
        }

        return $shipments;
    }

    /**
     * The barcode for an order the chain could not give a track — no msi-source, or no shipment
     * Magento would make. The order row is the only place left, and the grid falls back to it.
     *
     * Such an order gets a barcode and never a link: myparcel_tracktrace_url is a column on
     * sales_shipment_track.
     *
     * @param array<string,array<int,array{barcode: string, shipmentId: int|null}>> $fulfilment
     *
     * @return int how many orders were written
     */
    private function writeBarcodeWhereNoTrack(array $fulfilment): int
    {
        $written = 0;

        foreach ($fulfilment as $incrementId => $shipments) {
            $barcodes = array_values(array_unique(array_filter(
                array_column($shipments, 'barcode'),
                static function (string $barcode): bool {
                    return '' !== $barcode && ! in_array($barcode, TrackAndTrace::PLACEHOLDERS, true);
                }
            )));

            if (! $barcodes) {
                continue;
            }

            /** @var Order $magentoOrder */
            $magentoOrder = $this->objectManager->create(Order::class)
                                                ->loadByIncrementId((string) $incrementId);

            if (! $magentoOrder->getId() || $this->hasBarcodeOnTrack($magentoOrder)) {
                continue;
            }

            $this->setShippedWithoutShipment($magentoOrder, $barcodes);
            $written++;
        }

        return $written;
    }

    /** Whether any of this order's MyParcel tracks carries something other than a placeholder. */
    private function hasBarcodeOnTrack(Order $magentoOrder): bool
    {
        foreach ($magentoOrder->getTracksCollection() as $track) {
            $number = (string) $track->getTrackNumber();

            if (Carrier::CODE === $track->getCarrierCode()
                && '' !== $number
                && ! in_array($number, TrackAndTrace::PLACEHOLDERS, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one API call this cron makes. It sits in its own method because OrderCollection::query()
     * is static, so nothing can stand in for it where it is called.
     *
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    protected function queryApiOrders(string $apiKey): OrderCollection
    {
        return OrderCollection::query($apiKey);
    }

    /**
     * The increment ids to poll, grouped by their own store's API key, so no store is ever read out
     * of another store's account. A store with no key configured is skipped, never lent one.
     *
     * @param array[] $orderRows sales_order rows carrying increment_id and store_id
     *
     * @return array<string,string[]>
     */
    private function incrementIdsByApiKey(array $orderRows): array
    {
        $apiProvider = $this->objectManager->get(ShipmentApiProvider::class);
        $grouped     = [];

        foreach ($orderRows as $orderRow) {
            $apiKey = $apiProvider->apiKeyForStoreOrNull(
                isset($orderRow['store_id']) ? (int) $orderRow['store_id'] : null
            );

            if (null === $apiKey) {
                continue;
            }

            $incrementId = (string) $orderRow['increment_id'];
            // Keyed by value: the same increment id can appear twice in the row set.
            $grouped[$apiKey][$incrementId] = $incrementId;
        }

        return array_map('array_values', $grouped);
    }

    /**
     * Handles orders that have regular shipments, first removes any lingering orders in $this->orderCollection
     *
     * @throws LocalizedException
     * @throws Exception
     */
    private function updateStatusShipments(): self
    {
        // setNewMyParcelTracks() is deliberately absent, as it is in the PPS branch above: it
        // builds a v11 Shipment per id-less track — capabilities, customs, validation — for a
        // create this cron never issues, and nothing here reads what it collects.
        $this->setOrdersToUpdate();
        $this->orderCollection->updateMagentoTrack();

        return $this;
    }

    /**
     * @throws AlreadyExistsException
     */
    /**
     * A plain overwrite, not a merge: every run recomputes the order's full barcode list, so the
     * column ends up holding all of them either way.
     *
     * @param string[] $barcodes
     */
    private function setShippedWithoutShipment(Order $magentoOrder, array $barcodes): void
    {
        Logger::notice(
            sprintf(
                'Order %s set to shipped without shipment, carrying %d barcode(s)',
                $magentoOrder->getIncrementId(),
                count($barcodes)
            )
        );

        $magentoOrder->setData('track_number', json_encode(array_values($barcodes)));
        $this->orderResource->save($magentoOrder);
    }

    /**
     * @param array $shipment
     *
     * @return bool
     */
    private function apiShipmentIsShipped(array $shipment): bool
    {
        $status = $shipment['status'] ?? null;

        return $status >= ShipmentStatus::PRINTED_MINIMUM
            && (! in_array($status, [ShipmentStatus::CREDITED, ShipmentStatus::CANCELLED], true));
    }

    /**
     * Get all order to update the data
     *
     * @throws LocalizedException
     */
    private function setOrdersToUpdate()
    {
        $this->addOrdersToCollection(
            $this->getOrderIdFromTrackToUpdate()
        );

        return $this;
    }

    /**
     * Get all ids from orders that need to be updated
     *
     * @return array
     */
    private function getOrderIdFromTrackToUpdate()
    {
        /**
         * @var Order\Shipment\Track $magentoTrack
         * @var Collection           $trackCollection
         */
        $trackCollection = $this->objectManager->get(self::PATH_MODEL_ORDER_TRACK);
        $trackCollection
            ->addFieldToSelect('order_id')
            ->addAttributeToFilter('myparcel_status', [1, 2, 3, 4, 5, 6, 8])
            ->addAttributeToFilter('myparcel_consignment_id', ['notnull' => true])
            ->addAttributeToFilter(ShipmentTrackInterface::CARRIER_CODE, Carrier::CODE)
            ->setPageSize(300)
            ->setOrder('order_id', 'DESC');

        return array_unique(array_column($trackCollection->getData(), 'order_id'));
    }

    /**
     * Get collection from order ids
     *
     * @param int[] $orderIds
     */
    private function addOrdersToCollection(array $orderIds): void
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $now        = new DateTime('now -14 day');
        $collection = $this->objectManager->create(MagentoCollection::PATH_MODEL_ORDER_COLLECTION);
        $collection
            ->addAttributeToFilter('entity_id', ['in' => $orderIds])
            ->addFieldToFilter('created_at', ['gteq' => $now->format('Y-m-d H:i:s')]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
