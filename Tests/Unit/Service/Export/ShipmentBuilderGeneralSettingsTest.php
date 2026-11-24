<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

it('tells the API not to save the recipient address in the address book', function () {
    [$builder, $track] = createConvertibleShipmentBuilder(
        ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard', 'date' => '2026-08-20'],
        'test-api-key'
    );

    $shipment = $builder->build($track, ['carrier' => CarrierPostNL::NAME, 'insurance' => 0], 1);

    expect($shipment->getShipment()->getGeneralSettings()->getSaveRecipientAddress())->toBe(0);
});
