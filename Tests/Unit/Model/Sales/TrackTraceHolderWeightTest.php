<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

function createHolderForWeight(array $configValues = []): TrackTraceHolder
{
    return createTrackTraceHolder([
        'weight'      => new Weight(createConfig($configValues)),
        'consignment' => ConsignmentFactory::createByCarrierName(CarrierPostNL::NAME),
    ]);
}

it('uses the preset weight and never touches the shipment', function () {
    $holder = createHolderForWeight();
    // No getShipment() expectation: calculateTotalWeight() only reaches the
    // shipment when $presetWeightInGrams is not positive, so a call here
    // would mean the preset branch stopped short-circuiting.
    $track = Mockery::mock(Track::class);

    invokePrivateMethod($holder, 'calculateTotalWeight', [$track, 500, AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP]);

    expect($holder->consignment->getPhysicalProperties()['weight'])->toBe(500);
});

it('sums item weight times quantity plus the empty package weight, in grams', function () {
    $holder   = createHolderForWeight(['print/weight_indication' => 'gram', 'empty_package_weight/package' => '50']);
    $shipment = createShipment(['getItems' => [
        createShipmentItem(['weight' => 300.0, 'qty' => 2]),
        createShipmentItem(['weight' => 150.0, 'qty' => 1]),
    ]]);
    $track = createShipmentTrack(['getShipment' => $shipment]);

    invokePrivateMethod($holder, 'calculateTotalWeight', [$track, 0, AbstractConsignment::PACKAGE_TYPE_PACKAGE]);

    expect($holder->consignment->getPhysicalProperties()['weight'])->toBe(2 * 300 + 150 + 50);
});

it('sums item weight times quantity plus the empty package weight, in kilo mode', function () {
    $holder   = createHolderForWeight(['print/weight_indication' => 'kilo', 'empty_package_weight/mailbox' => '20']);
    $shipment = createShipment(['getItems' => [
        createShipmentItem(['weight' => 1.5, 'qty' => 2]),
    ]]);
    $track = createShipmentTrack(['getShipment' => $shipment]);

    invokePrivateMethod($holder, 'calculateTotalWeight', [$track, 0, AbstractConsignment::PACKAGE_TYPE_MAILBOX]);

    expect($holder->consignment->getPhysicalProperties()['weight'])->toBe((int) (1.5 * 2 * 1000) + 20);
});

it('floors a zero-weight shipment at the configured default weight', function () {
    $holder   = createHolderForWeight(['print/weight_indication' => 'gram']);
    $shipment = createShipment(['getItems' => [
        createShipmentItem(['weight' => 0.0, 'qty' => 1]),
    ]]);
    $track = createShipmentTrack(['getShipment' => $shipment]);

    invokePrivateMethod($holder, 'calculateTotalWeight', [$track, 0, AbstractConsignment::PACKAGE_TYPE_PACKAGE]);

    expect($holder->consignment->getPhysicalProperties()['weight'])->toBe(Weight::DEFAULT_WEIGHT);
});
