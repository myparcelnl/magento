<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentValidator;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentCustomsDeclaration;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentCustomsDeclarationItem;
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

it('checks customs and each of its items', function () {
    $item     = (new RefShipmentCustomsDeclarationItem())->setCountry('NL');
    $customs  = (new RefShipmentCustomsDeclaration())->setItems([$item]);
    $problems = (new ShipmentValidator())->problemsWith((new Shipment())->setCustomsDeclaration($customs));

    expect($problems)->toContain("customs: 'contents' can't be null")
        ->toContain("customs item 1: 'description' can't be null")
        ->not->toContain("customs item 1: invalid value 'NL' for 'country', must be one of ''");
});
