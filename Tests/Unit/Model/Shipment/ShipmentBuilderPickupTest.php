<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * A stored pickup ships as a pickup. The carrier change that turns a pickup into a home delivery
 * happens when the merchant saves it (OrderOptionsWriterTest), not at export.
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

it('keeps the pickup location of the stored carrier', function () {
    [$builder, $track] = createConvertibleShipmentBuilder(pickupCheckoutOptions());

    $shipment = $builder->build($track)->getShipment();

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

    $build = fn () => $builder->build($track);

    expect($build)->toThrow(RuntimeException::class)
        ->and(fn () => $build())->toThrow(function (RuntimeException $e) {
            expect($e->getMessage())->not->toContain('Order ');
        });
});

it('refuses a stored pickup without a location, never ships it home under the default carrier', function () {
    [$builder, $track] = createConvertibleShipmentBuilder([
        'carrier'      => CarrierDHLForYou::NAME,
        'deliveryType' => 'pickup',
        'isPickup'     => true,
        'date'         => '2026-08-20',
    ]);

    expect(fn () => $builder->build($track))->toThrow(RuntimeException::class, 'pickup location cannot be read');
});
