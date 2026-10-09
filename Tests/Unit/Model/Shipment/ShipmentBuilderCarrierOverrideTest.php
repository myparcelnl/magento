<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * A pickup location belongs to one carrier's network, so overriding the
 * carrier after checkout invalidates it and the shipment falls back to
 * standard home delivery.
 *
 * Read through ShipmentAccessors rather than consignment getters: the rule
 * survives the SDK migration, the shape it is read from does not.
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

    $shipment = $builder->build($track, ['carrier' => CarrierPostNL::NAME, 'insurance' => 0])->shipment();

    expect(builtShipmentIsPickup($shipment))->toBeFalse();
    expect(builtShipmentPickupPostalCode($shipment))->toBeNull();
});

it('preserves an inherited pickup location when the carrier is not overridden', function () {
    [$builder, $track] = createConvertibleShipmentBuilder(pickupCheckoutOptions());

    $shipment = $builder->build($track, ['carrier' => CarrierDHLForYou::NAME, 'insurance' => 0])->shipment();

    expect(builtShipmentIsPickup($shipment))->toBeTrue();
    expect(builtShipmentPickupPostalCode($shipment))->toBe('1000AA');
});

it('leaves naming the order to the reporting layer', function () {
    // The builder used to prefix 'Order %s: ' itself while both catch sites prefixed the increment id
    // again, so a local validation failure rendered as
    // "000000116: Order 000000116: recipient: invalid value for 'postal_code'…". One prefix, one owner.
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
