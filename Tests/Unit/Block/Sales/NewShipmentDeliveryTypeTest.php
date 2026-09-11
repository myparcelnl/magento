<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * The constructor is skipped: it stands up a Magento backend block context, while this method
 * reads only $order.
 */
function createNewShipmentBlockFor(?string $deliveryTypeName, string $incrementId = '100000042'): NewShipment
{
    $block = newInstanceWithoutConstructor(NewShipment::class);
    setPrivateProperty($block, 'order', createOrder([
        'getIncrementId'  => $incrementId,
        'deliveryOptions' => json_encode(
            null === $deliveryTypeName ? [] : ['deliveryType' => $deliveryTypeName]
        ),
    ]));

    return $block;
}

it('resolves a known delivery type without logging', function () {
    $logger = mockLoggerFacade();
    $logger->shouldNotReceive('warning');

    expect(createNewShipmentBlockFor('evening')->getDeliveryType())->toBe(DeliveryType::EVENING);
});

it('resolves early morning rather than silently substituting standard', function () {
    $logger = mockLoggerFacade();
    $logger->shouldNotReceive('warning');

    expect(createNewShipmentBlockFor('early_morning')->getDeliveryType())
        ->toBe(DeliveryType::EARLY_MORNING)
        ->not->toBe(DeliveryType::STANDARD);
});

it('returns null rather than standard when the stored delivery type is unrecognised', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return false !== strpos($message, 'teleport')
                && false !== strpos($message, '100000042');
        });

    expect(createNewShipmentBlockFor('teleport')->getDeliveryType())
        ->toBeNull()
        ->not->toBe(DeliveryType::STANDARD);
});

it('withholds the receipt code option when the delivery type is unrecognised', function () {
    mockLoggerFacade()->shouldReceive('warning');

    $block = createNewShipmentBlockFor('teleport');
    // Permissive says yes to everything, so a false here is the module's own standard-only rule
    // beating the capability answer rather than agreeing with it.
    setPrivateProperty($block, 'capabilities', array_fill_keys(
        array_merge([''], array_keys(PackageType::NAMES_IDS_MAP)),
        CapabilitySet::permissive()
    ));

    expect($block->hasShipmentOption(Carrier::POSTNL, PackageType::PACKAGE_NAME, ShipmentOption::RECEIPT_CODE))
        ->toBeFalse()
        ->and($block->hasShipmentOption(Carrier::POSTNL, PackageType::PACKAGE_NAME, ShipmentOption::SIGNATURE))
        ->toBeTrue();
});

it('defaults silently when the order carries no delivery type', function () {
    $logger = mockLoggerFacade();
    $logger->shouldNotReceive('warning');

    expect(createNewShipmentBlockFor(null)->getDeliveryType())->toBe(DeliveryType::STANDARD);
});
