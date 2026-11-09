<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * Reads facts off a built shipment without the tests naming its shape. Most of these facts live
 * inside the shipment options, not on the shipment. This covers only what is read off the built
 * object, not the return value of a decision rule.
 */
function builtShipmentIsPickup(Shipment $shipment): bool
{
    $options = $shipment->getOptions();

    return $options && DeliveryType::PICKUP === $options->getDeliveryType();
}

function builtShipmentPickupPostalCode(Shipment $shipment): ?string
{
    $pickup = $shipment->getPickup();

    return $pickup ? $pickup->getPostalCode() : null;
}

/** @return object[] the customs items, in the order they were added. */
function builtShipmentCustomsItems(Shipment $shipment): array
{
    $declaration = $shipment->getCustomsDeclaration();

    return $declaration ? $declaration->getItems() : [];
}

/**
 * The item value as amount-plus-currency.
 *
 * The legacy item returned this array directly; the v11 item returns a
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
