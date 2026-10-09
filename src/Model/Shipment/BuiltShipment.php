<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * One built Shipment together with everything the export needs to route it and write the result
 * back: the API key of its order's store, its Magento track, and the increment id that names it in
 * a report. A v11 Shipment carries none of that, and pairing by result order is not allowed.
 */
class BuiltShipment
{
    private Shipment $shipment;
    private Track    $track;
    private string   $apiKey;
    private string   $incrementId;

    /**
     * The order's remaining id-less tracks, when this is a multicollo.
     *
     * label_amount rows are created before anyone knows the order will ship as one multicollo, so
     * the rest would otherwise sit at consignment id 0 and be exported again as new billable
     * shipments. persist() stamps them with the parent's id; addSecondaryShipmentTracks() then
     * gives each its own collo id instead of adding a row.
     *
     * @var Track[]
     */
    private array $spareTracks = [];

    public function __construct(Shipment $shipment, Track $track, string $apiKey, string $incrementId)
    {
        $this->shipment    = $shipment;
        $this->track       = $track;
        $this->apiKey      = $apiKey;
        $this->incrementId = $incrementId;
    }

    public function shipment(): Shipment
    {
        return $this->shipment;
    }

    public function track(): Track
    {
        return $this->track;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function incrementId(): string
    {
        return $this->incrementId;
    }

    /** What create() echoes back, and therefore how a response row finds its way home. */
    public function referenceIdentifier(): string
    {
        return (string) $this->shipment->getReferenceIdentifier();
    }

    /** Replaces the Shipment while keeping the pairing — multicollo returns a new object. */
    public function withShipment(Shipment $shipment): self
    {
        $clone = new self($shipment, $this->track, $this->apiKey, $this->incrementId);
        $clone->spareTracks = $this->spareTracks;

        return $clone;
    }

    /** @param Track[] $tracks */
    public function withSpareTracks(array $tracks): self
    {
        $clone = new self($this->shipment, $this->track, $this->apiKey, $this->incrementId);
        $clone->spareTracks = array_values($tracks);

        return $clone;
    }

    /** @return Track[] */
    public function spareTracks(): array
    {
        return $this->spareTracks;
    }
}
