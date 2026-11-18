<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * A pickup location belongs to one carrier's network, so overriding the
 * carrier after checkout invalidates it and the shipment falls back to
 * standard home delivery.
 */
function pickupCheckoutOptions(): array
{
    return [
        'carrier'        => CarrierDHLForYou::NAME,
        'deliveryType'   => 'pickup',
        'isPickup'       => true,
        'date'           => '2026-08-20',
        'pickupLocation' => [
            'location_name' => 'Shop',
            'location_code' => 'X1',
            'street'        => 'Main',
            'number'        => '1',
            'postal_code'   => '1000AA',
            'city'          => 'Berlin',
            'cc'            => 'DE',
        ],
    ];
}

it('clears an inherited pickup location when the carrier is overridden', function () {
    [$builder, $track] = createConvertibleShipmentBuilder(pickupCheckoutOptions());

    $shipment = $builder->build($track, ['carrier' => CarrierPostNL::NAME, 'insurance' => 0])->getShipment();

    expect(builtShipmentIsPickup($shipment))->toBeFalse();
    expect(builtShipmentPickupPostalCode($shipment))->toBeNull();
});

it('preserves an inherited pickup location when the carrier is not overridden', function () {
    [$builder, $track] = createConvertibleShipmentBuilder(pickupCheckoutOptions());

    $shipment = $builder->build($track, ['carrier' => CarrierDHLForYou::NAME, 'insurance' => 0])->getShipment();

    expect(builtShipmentIsPickup($shipment))->toBeTrue();
    expect(builtShipmentPickupPostalCode($shipment))->toBe('1000AA');
});

it('leaves naming the order to the reporting layer', function () {
    // Both catch sites prefix the increment id, so the builder adds no order prefix of its own.
    [$builder, $track] = createConvertibleShipmentBuilder([
        'carrier'      => CarrierPostNL::NAME,
        'deliveryType' => 'pickup',
        'isPickup'     => true,
        'date'         => '2026-08-20',
    ]);

    $build = fn () => $builder->build($track, ['carrier' => CarrierPostNL::NAME, 'insurance' => 0]);

    expect($build)->toThrow(RuntimeException::class)
        ->and(fn () => $build())->toThrow(function (RuntimeException $e) {
            expect($e->getMessage())->not->toContain('Order ');
        });
});
