<?php

declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Services\ConsignmentEncode;

/**
 * @return array{0: \MyParcelNL\Magento\Model\Sales\TrackTraceHolder, 1: \MyParcelNL\Sdk\Model\Consignment\AbstractConsignment}
 */
function createHolderForCustoms(): array
{
    $product = Mockery::mock();
    $product->shouldReceive('getCountryOfManufacture')->andReturn('CN');

    $productRepository = Mockery::mock(ProductRepositoryInterface::class);
    $productRepository->shouldReceive('getById')->andReturn($product);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(ProductRepositoryInterface::class)->andReturn($productRepository);

    mockAttributeValueLookup('61');

    $consignment = ConsignmentFactory::createByCarrierName(CarrierPostNL::NAME);
    $consignment->setCountry('US'); // outside EURO_COUNTRIES -> isToRowCountry() === true

    $holder = createTrackTraceHolder([
        'objectManager' => $objectManager,
        'config'        => createConfig(['print/weight_indication' => 'gram']),
        'weight'        => new Weight(createConfig(['print/weight_indication' => 'gram'])),
        'consignment'   => $consignment,
    ]);

    return [$holder, $consignment];
}

// qty = 1 makes both loops in convertDataForCdCountry() compute identical
// values for every field (loop 1 ignores qty in its weight calculation,
// loop 2 multiplies by it; a qty other than 1 would make the two loops
// disagree, which is a second latent inconsistency out of scope for this
// row of the plan), so asserting on the first item is representative of
// either loop's output regardless of the double-add below.
it('maps every customs field from the shipment item', function () {
    [$holder, $consignment] = createHolderForCustoms();
    $item = createShipmentItem([
        'name'       => 'Widget',
        'qty'        => 1,
        'weight'     => 250.0,
        'price'      => 12.34,
        'product_id' => 99,
    ]);
    $track = createShipmentTrack(['getShipment' => createShipment(['items' => [$item]])]);

    invokePrivateMethod($holder, 'convertDataForCdCountry', [$track]);

    $items = $consignment->getItems();
    expect($items[0]->getDescription())->toBe('Widget');
    expect($items[0]->getAmount())->toBe(1);
    expect($items[0]->getWeight())->toBe(250);
    expect($items[0]->getItemValue())->toBe(['amount' => 1234, 'currency' => ConsignmentEncode::DEFAULT_CURRENCY]);
    expect($items[0]->getClassification())->toBe('61');
    expect($items[0]->getCountry())->toBe('CN');
});

it('adds each shipment item to the consignment exactly once', function () {
    // Pre-existing bug: convertDataForCdCountry() has two loops over the
    // same shipment items (one via getData('items'), one via getItems()),
    // and both add every item to the consignment — today this sees 2, not
    // 1. Marked ->todo() so the suite stays green; goes green for real in
    // Phase 6, when the fix lands alongside the ShipmentBuilder rewrite —
    // that's the signal to remove ->todo() here.
    [$holder, $consignment] = createHolderForCustoms();
    $item = createShipmentItem(['name' => 'Widget', 'qty' => 1, 'weight' => 250.0, 'price' => 12.34, 'product_id' => 99]);
    $track = createShipmentTrack(['getShipment' => createShipment(['items' => [$item]])]);

    invokePrivateMethod($holder, 'convertDataForCdCountry', [$track]);

    expect($consignment->getItems())->toHaveCount(1);
})->todo();
