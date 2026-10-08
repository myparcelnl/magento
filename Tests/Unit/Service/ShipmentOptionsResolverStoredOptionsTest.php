<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * A merchant switches a refused option off on the order. The carrier setting must not switch it
 * back on at export, while an option the order leaves inheriting still follows that setting.
 */
function resolvedWithStoredSignature(?bool $stored): ?bool
{
    $blob = ['carrier' => 'postnl', 'deliveryType' => 'standard', 'shipmentOptions' => [ShipmentOption::SIGNATURE => $stored]];

    // The fixture stubs whatever it is given, so the real signature answers go through a double.
    $real     = defaultOptionsFor(['signature_active' => '1'], 10.0, 'NL', 'NL', json_encode($blob));
    $defaults = Mockery::mock(DefaultOptions::class);
    $defaults->shouldReceive('hasOptionSet')->with(ShipmentOption::SIGNATURE, Mockery::any())->andReturnUsing([$real, 'hasOptionSet']);
    $defaults->shouldReceive('sourceOf')->with(ShipmentOption::SIGNATURE, Mockery::any())->andReturnUsing([$real, 'sourceOf']);

    return createShipmentOptions('NL', 'postnl', [], false, $blob, null, $defaults)->resolve()->hasSignature();
}

it('keeps an option off that the order stores off, against the carrier setting', function () {
    expect(resolvedWithStoredSignature(false))->toBeFalse();
});

it('follows the carrier setting for an option the order leaves inheriting', function () {
    expect(resolvedWithStoredSignature(null))->toBeTrue();
});
