<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

/**
 * Reads facts off a built shipment without the tests naming its shape.
 *
 * Phase 1 pins our rules; the object those rules land on is about to change.
 * Today it is an SDK AbstractConsignment. Phase 6 replaces it with a v11
 * Shipment, where the delivery type moves inside ShipmentOptions and the
 * pickup location is set through setPickup(). These accessors are the single
 * place that changes when it does, so the rule tests themselves stay
 * untouched — which is what keeps "a Phase 1 test needed editing" a real
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
