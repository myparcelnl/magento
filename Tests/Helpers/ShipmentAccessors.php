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
 * @return array{amount: int, currency: string}
 */
function customsItemValue(object $item): array
{
    $value = $item->getItemValue();

    return ['amount' => $value->getAmount(), 'currency' => $value->getCurrency()];
}
