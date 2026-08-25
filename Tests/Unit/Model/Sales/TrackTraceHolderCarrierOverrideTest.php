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
    [$holder, $track] = createConvertibleTrackTraceHolder(pickupCheckoutOptions());

    $holder->convertDataFromMagentoToApi($track, ['carrier' => CarrierPostNL::NAME, 'insurance' => 0]);

    expect(builtShipmentIsPickup($holder->consignment))->toBeFalse();
    expect(builtShipmentPickupPostalCode($holder->consignment))->toBeNull();
});

it('preserves an inherited pickup location when the carrier is not overridden', function () {
    [$holder, $track] = createConvertibleTrackTraceHolder(pickupCheckoutOptions());

    $holder->convertDataFromMagentoToApi($track, ['carrier' => CarrierDHLForYou::NAME, 'insurance' => 0]);

    expect(builtShipmentIsPickup($holder->consignment))->toBeTrue();
    expect(builtShipmentPickupPostalCode($holder->consignment))->toBe('1000AA');
});
