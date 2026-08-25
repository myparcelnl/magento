<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

/**
 * Reads facts off a built shipment without the tests naming its shape.
 *
 * Phase 6 swaps the SDK consignment for a v11 Shipment. This is the one place
 * that changes when it does, so a rule test needing an edit stays a real
 * signal of a behaviour change rather than a rename.
 */
function builtShipmentIsPickup(object $shipment): bool
{
    return DeliveryType::PICKUP === $shipment->getDeliveryType();
}

function builtShipmentPickupPostalCode(object $shipment): ?string
{
    return $shipment->getPickupPostalCode();
}
