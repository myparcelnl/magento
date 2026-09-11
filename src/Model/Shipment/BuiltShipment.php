<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * One built Shipment together with everything the export needs to route it and write the result
 * back: the API key of its order's store, its Magento track, and the increment id that names it in
 * a report. A v11 Shipment carries none of that, and TR-000006 forbids pairing by result order.
 */
class BuiltShipment
{
    private Shipment $shipment;
    private Track    $track;
    private string   $apiKey;
    private string   $incrementId;

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
        return new self($shipment, $this->track, $this->apiKey, $this->incrementId);
    }
}
