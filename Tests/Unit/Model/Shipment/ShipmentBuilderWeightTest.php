<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentBuilder;
use MyParcelNL\Magento\Service\Weight;

/**
 * weightInGrams() returns the weight where calculateTotalWeight() set it on the
 * consignment, so these read the return value rather than the built object. The
 * expected numbers are unchanged; only where the answer is read from moved.
 *
 * The digital stamp preset is DefaultOptions::getDigitalStampDefaultWeight(): the weight saved on
 * the order, else the configured default.
 */
function createBuilderForWeight(array $configValues = []): ShipmentBuilder
{
    return createShipmentBuilder([
        'weight' => new Weight(createConfig($configValues)),
    ]);
}

it('uses the preset weight and never touches the shipment', function () {
    $defaultOptions = Mockery::mock(MyParcelNL\Magento\Model\Source\DefaultOptions::class);
    $defaultOptions->shouldReceive('getDigitalStampDefaultWeight')->andReturn(500);

    $builder = createShipmentBuilder(['weight' => new Weight(createConfig()), 'defaultOptions' => $defaultOptions]);
    // No getShipment() expectation: weightInGrams() only reaches the shipment
    // for a package type other than digital stamp, so a call here would mean
    // the preset branch stopped short-circuiting.
    $track = Mockery::mock(Track::class);

    $weight = invokePrivateMethod($builder, 'weightInGrams', [$track, PackageType::DIGITAL_STAMP]);

    expect($weight)->toBe(500);
});

it('falls back to the item weights when no digital stamp weight is preset anywhere', function () {
    // Nothing saved and no default configured casts to 0, and the API refuses a zero weight — the
    // consignment path summed the items in that case, so this does too.
    $defaultOptions = Mockery::mock(MyParcelNL\Magento\Model\Source\DefaultOptions::class);
    $defaultOptions->shouldReceive('getDigitalStampDefaultWeight')->andReturn(0);

    $builder = createShipmentBuilder([
        'weight'         => new Weight(createConfig(['print/weight_indication' => 'gram'])),
        'defaultOptions' => $defaultOptions,
    ]);
    $shipment = createShipment(['getItems' => [
        createShipmentItem(['weight' => 120.0, 'qty' => 2]),
    ]]);
    $track = createShipmentTrack(['getShipment' => $shipment]);

    $weight = invokePrivateMethod($builder, 'weightInGrams', [$track, PackageType::DIGITAL_STAMP]);

    expect($weight)->toBe(240);
});

it('sums item weight times quantity plus the empty package weight, in grams', function () {
    $builder  = createBuilderForWeight(['print/weight_indication' => 'gram', 'empty_package_weight/package' => '50']);
    $shipment = createShipment(['getItems' => [
        createShipmentItem(['weight' => 300.0, 'qty' => 2]),
        createShipmentItem(['weight' => 150.0, 'qty' => 1]),
    ]]);
    $track = createShipmentTrack(['getShipment' => $shipment]);

    $weight = invokePrivateMethod($builder, 'weightInGrams', [$track, PackageType::PACKAGE]);

    expect($weight)->toBe(2 * 300 + 150 + 50);
});

it('sums item weight times quantity plus the empty package weight, in kilo mode', function () {
    $builder  = createBuilderForWeight(['print/weight_indication' => 'kilo', 'empty_package_weight/mailbox' => '20']);
    $shipment = createShipment(['getItems' => [
        createShipmentItem(['weight' => 1.5, 'qty' => 2]),
    ]]);
    $track = createShipmentTrack(['getShipment' => $shipment]);

    $weight = invokePrivateMethod($builder, 'weightInGrams', [$track, PackageType::MAILBOX]);

    expect($weight)->toBe((int) (1.5 * 2 * 1000) + 20);
});

it('floors a zero-weight shipment at the configured default weight', function () {
    $builder  = createBuilderForWeight(['print/weight_indication' => 'gram']);
    $shipment = createShipment(['getItems' => [
        createShipmentItem(['weight' => 0.0, 'qty' => 1]),
    ]]);
    $track = createShipmentTrack(['getShipment' => $shipment]);

    $weight = invokePrivateMethod($builder, 'weightInGrams', [$track, PackageType::PACKAGE]);

    expect($weight)->toBe(Weight::DEFAULT_WEIGHT);
});

it('sends the dimensions a merchant saved beside the weight', function () {
    $stored = \MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions::fromCheckoutData([
        'deliveryType'       => 'standard',
        'physicalProperties' => ['length' => 40, 'width' => 30, 'height' => 20],
    ]);

    expect(invokePrivateMethod(createBuilderForWeight(), 'physicalProperties', [1200, $stored]))
        ->toBe(['weight' => 1200, 'length' => 40, 'width' => 30, 'height' => 20])
        ->and(invokePrivateMethod(createBuilderForWeight(), 'physicalProperties', [
            1200,
            \MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions::fromCheckoutData(['deliveryType' => 'standard']),
        ]))->toBe(['weight' => 1200]);
});
