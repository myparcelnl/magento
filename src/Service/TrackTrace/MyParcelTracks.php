<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\TrackTrace;

use Magento\Framework\DB\Select;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use MyParcelNL\Magento\Model\Carrier\Carrier;

/**
 * Restricts a track query to the tracks this module owns.
 *
 * An order can carry tracks from any Magento carrier. Reading another carrier's track here means
 * stamping its barcode onto sales_order.track_number and offering a MyParcel portal link for a
 * barcode that portal never issued. The rule was spelled out at each query and missing from two,
 * so it lives here instead.
 */
class MyParcelTracks
{
    /**
     * @param string $alias the track table's alias in $select
     */
    public static function scopeSelect(Select $select, string $alias): Select
    {
        return $select->where($alias . '.carrier_code = ?', Carrier::CODE);
    }

    /**
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $collection
     */
    public static function scopeCollection($collection)
    {
        return $collection->addAttributeToFilter(ShipmentTrackInterface::CARRIER_CODE, Carrier::CODE);
    }

    /**
     * Whether one already-loaded track row is ours, for a caller handed a collection it did not
     * query — the shipment's own getTracksCollection() carries every carrier's.
     *
     * A row with no carrier_code at all counts as ours: the module's own in-memory tracks are built
     * before the column is set, and dropping them would blank a barcode this pass just minted.
     *
     * @param array<string,mixed>|\Magento\Framework\DataObject $track
     */
    public static function isOwn($track): bool
    {
        $carrierCode = $track['carrier_code'] ?? null;

        return null === $carrierCode || Carrier::CODE === $carrierCode;
    }
}
