<?php

declare(strict_types=1);

use MyParcelNL\Magento\ViewModel\ShipmentOptionsForm;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * The constructor is skipped: it stands up a Magento backend block context, while this method
 * reads only $order.
 */
function createNewShipmentBlockFor(?string $deliveryTypeName, string $incrementId = '100000042'): ShipmentOptionsForm
{
    $block = newInstanceWithoutConstructor(ShipmentOptionsForm::class);
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

it('leaves receipt code to capabilities whatever the delivery type', function (string $deliveryType) {
    mockLoggerFacade()->shouldReceive('warning');

    $block = createNewShipmentBlockFor($deliveryType);
    // Permissive says yes to everything, so a false here would be a module rule of its own.
    setPrivateProperty($block, 'capabilityLookup', capabilityLookupWith(CapabilitySet::permissive(), '', 1));

    expect($block->hasShipmentOption('postnl', PackageType::PACKAGE_NAME, ShipmentOption::RECEIPT_CODE))
        ->toBeTrue();
})->with(['evening', 'teleport']);

it('defaults silently when the order carries no delivery type', function () {
    $logger = mockLoggerFacade();
    $logger->shouldNotReceive('warning');

    expect(createNewShipmentBlockFor(null)->getDeliveryType())->toBe(DeliveryType::STANDARD);
});
