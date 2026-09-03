<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * Reads facts off a built shipment without the tests naming its shape.
 *
 * Phase 6 swapped the SDK consignment for a v11 Shipment, which spells every one
 * of these differently — most of them now live inside options rather than on the
 * shipment. Routing the reads through here is what let the rule tests keep their
 * expect() lines across that swap.
 *
 * This covers only what is read *off the built object*. A test asserting the
 * return value of a decision rule is not insulated by anything here.
 */
function builtShipmentIsPickup(Shipment $shipment): bool
{
    return DeliveryType::PICKUP === builtShipmentDeliveryType($shipment);
}

function builtShipmentPickupPostalCode(Shipment $shipment): ?string
{
    $pickup = $shipment->getPickup();

    return $pickup ? $pickup->getPostalCode() : null;
}

function builtShipmentDeliveryType(Shipment $shipment): ?int
{
    $options = $shipment->getOptions();

    return $options ? $options->getDeliveryType() : null;
}

function builtShipmentPackageType(Shipment $shipment): ?int
{
    $options = $shipment->getOptions();

    return $options ? $options->getPackageType() : null;
}

function builtShipmentWeight(Shipment $shipment): ?int
{
    $properties = $shipment->getPhysicalProperties();

    return $properties ? $properties->getWeight() : null;
}

/** In whole euros, the module's own scale — the request carries cents. */
function builtShipmentInsurance(Shipment $shipment): ?int
{
    $options   = $shipment->getOptions();
    $insurance = $options ? $options->getInsurance() : null;

    return $insurance ? (int) ($insurance->getAmount() / 100) : null;
}

function builtShipmentLabelDescription(Shipment $shipment): ?string
{
    $options = $shipment->getOptions();

    return $options ? $options->getLabelDescription() : null;
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
