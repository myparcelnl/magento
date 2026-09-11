<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Block\Sales\NewShipmentForm;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Weight;

/**
 * The constructor is skipped: it stands up a Magento backend block context and an ObjectManager
 * lookup, while these methods read only $order and the capability lookup.
 *
 * capabilityLookupWith() seeds the shapes; see its doc block for the key shape and for what an
 * unseeded shape does.
 *
 * @param CapabilitySet|array<string,CapabilitySet> $capabilities
 */
function createNewShipmentBlockWith($capabilities, array $orderOverrides = []): NewShipment
{
    $block = newInstanceWithoutConstructor(NewShipment::class);

    $order = createOrder(array_merge([
        'deliveryOptions' => json_encode(['deliveryType' => 'standard']),
    ], $orderOverrides));

    setPrivateProperty($block, 'order', $order);
    setPrivateProperty($block, 'capabilityLookup', capabilityLookupWith(
        $capabilities,
        $order->getShippingAddress() ? $order->getShippingAddress()->getCountryId() : '',
        (int) $order->getStoreId()
    ));
    setPrivateProperty($block, 'form', new NewShipmentForm());

    // getFormCarriers() reaches these whenever a shape reports insurance, which a permissive shape
    // always does.
    $defaults = Mockery::mock(DefaultOptions::class);
    $defaults->shouldReceive('getDefaultInsurance')->andReturn(0)->byDefault();
    $defaults->shouldReceive('getDigitalStampDefaultWeight')->andReturn(0)->byDefault();
    setPrivateProperty($block, 'defaultOptions', $defaults);

    $weight = Mockery::mock(Weight::class);
    $weight->shouldReceive('convertToGrams')->andReturn(0)->byDefault();
    setPrivateProperty($block, 'weightService', $weight);

    return $block;
}
