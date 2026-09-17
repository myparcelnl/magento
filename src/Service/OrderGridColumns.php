<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Grid;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Magento\Service\IdList;
use MyParcelNL\Magento\Service\TrackTrace\MyParcelTracks;

/**
 * Writes sales_order.track_status and track_number for a page of orders: one tracks query, one read
 * of the current values, and a column-level UPDATE only where they differ.
 *
 * Not $order->save(): that rewrote every column of a possibly stale order and re-synced the grid once
 * per shipment. The grid row is refreshed the way Magento's GridSyncInsertObserver does it: directly
 * when dev/grid/async_indexing is off, and through the updated_at bump refreshBySchedule() keys on
 * when it is on.
 */
class OrderGridColumns
{
    private const ASYNC_GRID_INDEXING = 'dev/grid/async_indexing';

    private ResourceConnection   $resource;
    private Grid                 $orderGrid;
    private ScopeConfigInterface $scopeConfig;
    private AreaList             $areaList;
    private State                $appState;

    public function __construct(
        ResourceConnection   $resource,
        Grid                 $orderGrid,
        ScopeConfigInterface $scopeConfig,
        AreaList             $areaList,
        State                $appState
    )
    {
        $this->resource    = $resource;
        $this->orderGrid   = $orderGrid;
        $this->scopeConfig = $scopeConfig;
        $this->areaList    = $areaList;
        $this->appState    = $appState;
    }

    /**
     * @param int[] $orderIds
     *
     * @return int how many orders were written
     */
    public function writeFor(array $orderIds): int
    {
        $orderIds = IdList::ints($orderIds);

        if (! $orderIds) {
            return 0;
        }

        $this->loadAdminTranslations();

        $tracks  = $this->tracksByOrder($orderIds);
        $current = $this->currentColumns($orderIds);
        $written = 0;

        foreach ($orderIds as $orderId) {
            if ($this->write($orderId, $this->htmlForTracks($tracks[$orderId] ?? []), $current[$orderId] ?? [])) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * The same write for a caller that already holds the tracks. The shipment observer does: its
     * tracks have no entity id yet, so the query writeFor() runs cannot see them.
     *
     * @param array{track_status: string, track_number: string} $columns
     *
     * @return bool whether anything was written
     */
    public function writeColumns(int $orderId, array $columns): bool
    {
        return $this->write($orderId, $columns, $this->currentColumns([$orderId])[$orderId] ?? []);
    }

    /**
     * @param array{track_status: string, track_number: string} $columns
     * @param array<string,string>                              $current what the order carries now
     *
     * @return bool whether anything was written
     */
    private function write(int $orderId, array $columns, array $current): bool
    {
        // A column the tracks cannot fill is left as it is, never blanked.
        $changed = array_diff_assoc(array_filter($columns), $current);

        if (! $changed) {
            return false;
        }

        $this->resource->getConnection()->update(
            $this->resource->getTableName('sales_order'),
            $changed + ['updated_at' => gmdate('Y-m-d H:i:s')],
            ['entity_id = ?' => $orderId]
        );

        if (! $this->scopeConfig->getValue(self::ASYNC_GRID_INDEXING)) {
            $this->orderGrid->refresh($orderId);
        }

        return true;
    }

    /**
     * The two column values one order's tracks add up to; '' where they say nothing.
     *
     * Scoped here rather than trusted from the caller: writeFor() queries its own rows, but the
     * shipment observer hands over $shipment->getTracksCollection(), which carries every carrier's.
     * A manually added DHL barcode reaching sales_order.track_number is then offered a MyParcel
     * portal link that portal never issued.
     *
     * @param iterable<array<string,mixed>|\Magento\Sales\Model\Order\Shipment\Track> $tracks
     *
     * @return array{track_status: string, track_number: string}
     */
    public function htmlForTracks(iterable $tracks): array
    {
        $statuses = [];
        $numbers  = [];

        foreach ($tracks as $track) {
            if (! MyParcelTracks::isOwn($track)) {
                continue;
            }

            if (null !== $track['myparcel_status']) {
                $statuses[] = __('status_' . $track['myparcel_status']);
            }

            // A placeholder is not a track number: stamped onto sales_order.track_number it used to
            // lock an order out of the PPS status cron for good.
            if (TrackAndTrace::isRealBarcode($track['track_number'])) {
                $numbers[] = $track['track_number'];
            }
        }

        return [
            'track_status' => implode('<br>', $statuses),
            'track_number' => $numbers ? json_encode($numbers) : '',
        ];
    }

    /**
     * @param int[] $orderIds
     *
     * @return array<int,array<int,array<string,mixed>>> track rows grouped by order id
     */
    private function tracksByOrder(array $orderIds): array
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
                                 ->from(['main_table' => $this->resource->getTableName('sales_shipment_track')])
                                 ->where('main_table.order_id IN (?)', $orderIds)
                                 ->order('main_table.entity_id ASC');

        MyParcelTracks::scopeSelect($select, 'main_table');

        $grouped = [];

        foreach ($connection->fetchAll($select) as $row) {
            $grouped[(int) $row['order_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param int[] $orderIds
     *
     * @return array<int,array{track_status: string, track_number: string}> keyed by order id
     */
    private function currentColumns(array $orderIds): array
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
                                 ->from($this->resource->getTableName('sales_order'), ['entity_id', 'track_status', 'track_number'])
                                 ->where('entity_id IN (?)', $orderIds);

        $current = [];

        foreach ($connection->fetchAll($select) as $row) {
            $current[(int) $row['entity_id']] = [
                'track_status' => (string) ($row['track_status'] ?? ''),
                'track_number' => (string) ($row['track_number'] ?? ''),
            ];
        }

        return $current;
    }

    /**
     * The status labels are translated, and outside adminhtml — the cron — nothing has loaded the
     * admin translations yet. Once per call; it used to run once per order.
     */
    private function loadAdminTranslations(): void
    {
        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            $areaCode = null;
        }

        if (Area::AREA_ADMINHTML === $areaCode) {
            return;
        }

        $this->areaList->getArea(Area::AREA_ADMINHTML)->load(Area::PART_TRANSLATE);
    }
}
