<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;

/**
 * Reads facts off a built shipment without the tests naming its shape.
 *
 * Phase 6 swaps the SDK consignment for a v11 Shipment, which spells every one
 * of these differently. Routing the reads through here keeps a rule test's
 * expect() line a real signal of a behaviour change rather than a rename.
 *
 * This covers only what is read *off the built object*. A test asserting the
 * return value of a decision rule is not insulated by anything here.
 */
function builtShipmentIsPickup(object $shipment): bool
{
    return DeliveryType::PICKUP === $shipment->getDeliveryType();
}

function builtShipmentPickupPostalCode(object $shipment): ?string
{
    return $shipment->getPickupPostalCode();
}

function builtShipmentDeliveryType(object $shipment): ?int
{
    return $shipment->getDeliveryType();
}

function builtShipmentPackageType(object $shipment): ?int
{
    return $shipment->getPackageType();
}

function builtShipmentWeight(object $shipment): ?int
{
    return $shipment->getPhysicalProperties()['weight'] ?? null;
}

function builtShipmentInsurance(object $shipment): ?int
{
    return $shipment->getInsurance();
}

function builtShipmentLabelDescription(object $shipment): ?string
{
    return $shipment->getLabelDescription();
}

/** @return object[] the customs items, in the order they were added. */
function builtShipmentCustomsItems(object $shipment): array
{
    return $shipment->getItems();
}

/**
 * The item value as amount-plus-currency.
 *
 * The legacy item returns this array directly; the v11 item returns a
 * RefTypesMoney, so only this one customs getter needs insulating.
 *
 * @return array{amount: int, currency: string}
 */
function customsItemValue(object $item): array
{
    $value = $item->getItemValue();

    if (is_array($value)) {
        return $value;
    }

    return ['amount' => $value->getAmount(), 'currency' => $value->getCurrency()];
}
