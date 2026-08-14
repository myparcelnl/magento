<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * A pickup location is carrier-specific — its location code and retail
 * network belong to one carrier's network. Overriding the carrier after
 * checkout therefore invalidates any inherited pickup location, and the
 * shipment falls back to standard home delivery. That rule is ours and
 * survives the SDK migration; the shape it is read from does not, so these
 * tests go through Tests/Helpers/ShipmentAccessors.php rather than naming
 * consignment getters directly.
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
