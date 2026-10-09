<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentValidator;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentCustomsDeclaration;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * The pre-flight check that turns what would be a batch-level API error into a per-order message.
 */
it('reports the shipment\'s own problems when nothing is nested yet', function () {
    expect((new ShipmentValidator())->problemsWith(new Shipment()))
        ->toContain("'recipient' can't be null")
        ->toContain("'carrier' can't be null");
});

it('recurses into a nested model and labels whose problem it is', function () {
    $shipment = (new Shipment())->setRecipient(['city' => 'Hoofddorp']);

    $problems = (new ShipmentValidator())->problemsWith($shipment);

    expect($problems)->toContain("recipient: 'cc' can't be null")
        ->toContain("recipient: 'street' can't be null")
        // Shipment::valid() does not recurse, which is the whole reason this class exists.
        ->and($shipment->listInvalidProperties())->not->toContain("recipient: 'cc' can't be null");
});

it('leaves customs alone, because its items report a false invalid country for every country we send', function () {
    $shipment = (new Shipment())->setCustomsDeclaration(new RefShipmentCustomsDeclaration());

    $customsProblems = array_filter(
        (new ShipmentValidator())->problemsWith($shipment),
        static fn(string $problem): bool => false !== stripos($problem, 'customs')
    );

    expect($customsProblems)->toBe([]);
});
