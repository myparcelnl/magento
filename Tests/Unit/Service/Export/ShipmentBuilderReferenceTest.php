<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

function referenceOfBuilt(array $shipmentOverrides, int $collo = 1): string
{
    [$builder, $track] = createConvertibleShipmentBuilder(
        ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard', 'date' => '2026-08-20'],
        'test-api-key',
        1,
        null,
        $shipmentOverrides
    );

    return $builder->build($track, $collo)->getReferenceIdentifier();
}

it('references a saved shipment by its entity id and collo number', function () {
    expect(referenceOfBuilt(['getEntityId' => 123], 2))->toBe('123-2');
});

it('references a shipment that is not saved yet by the order number', function () {
    expect(referenceOfBuilt(['getEntityId' => null]))->toBe('100000001-1');
});
