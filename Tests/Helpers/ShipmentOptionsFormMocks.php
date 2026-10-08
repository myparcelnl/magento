<?php

declare(strict_types=1);

use MyParcelNL\Magento\ViewModel\ShipmentOptionsForm;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Weight;

/**
 * The constructor is skipped: these methods read only $order, the capability lookup and the
 * collaborators set below.
 *
 * capabilityLookupWith() seeds the shapes; see its doc block for the key shape and for what an
 * unseeded shape does.
 *
 * @param CapabilitySet|array<string,CapabilitySet> $capabilities
 * @param string[]                                   $contracted v2 carrier names in the order store's
 *                                                               stored contract; none reads permissive
 */
function createShipmentOptionsFormWith($capabilities, array $orderOverrides = [], array $contracted = []): ShipmentOptionsForm
{
    $block = newInstanceWithoutConstructor(ShipmentOptionsForm::class);

    $order = createOrder(array_merge([
        'deliveryOptions' => json_encode(['deliveryType' => 'standard']),
    ], $orderOverrides));

    setPrivateProperty($block, 'order', $order);
    setPrivateProperty($block, 'capabilityLookup', capabilityLookupWith(
        $capabilities,
        $order->getShippingAddress() ? $order->getShippingAddress()->getCountryId() : '',
        (int) $order->getStoreId()
    ));
    setPrivateProperty($block, 'contractDefinitions', storedContractFor((int) $order->getStoreId(), $contracted));

    // getFormCarriers() reaches these whenever a shape reports insurance, which a permissive shape
    // always does.
    $defaults = Mockery::mock(DefaultOptions::class);
    $defaults->shouldReceive('getDefaultInsurance')->andReturn(0)->byDefault();
    $defaults->shouldReceive('getDigitalStampDefaultWeight')->andReturn(0)->byDefault();
    $defaults->shouldReceive('getSavedDigitalStampWeight')->andReturn(null)->byDefault();
    setPrivateProperty($block, 'defaultOptions', $defaults);

    $weight = Mockery::mock(Weight::class);
    $weight->shouldReceive('convertToGrams')->andReturn(0)->byDefault();
    setPrivateProperty($block, 'weightService', $weight);

    return $block;
}
