<?php

declare(strict_types=1);

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Api\ShipmentStatus;
use MyParcelNL\Magento\Cron\UpdateStatus;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Tests\Stub\RecordingUpdateStatus;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderCollection;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;

/**
 * The PPS branch used to read one API key with no store id, so a multi-account install only ever
 * polled one account. These pin the grouping, the isolation between accounts, and that no store is
 * matched against another store's response.
 */

/** Store 1 resolves to KEY_A, store 2 to KEY_B, store 3 to nothing at all. */
function updateStatusConfig(): MyParcelNL\Magento\Service\Config
{
    return createConfig([], [], [
        1 => ['api/key' => 'KEY_A'],
        2 => ['api/key' => 'KEY_B'],
    ]);
}

// apiOrderWithShipments(), shippedApiOrder() and conceptApiOrder() live in
// Tests/Helpers/FulfilmentApiFixtures.php.

/**
 * @param array<int,array{increment_id:string,entity_id:int,store_id:int}> $orderRows
 * @param array<string,OrderCollection>                                    $responses
 *
 * @return array{0: RecordingUpdateStatus, 1: MagentoOrderCollection, 2: object} the cron, the
 *         collection the matched orders land in, and a recorder for what was handed to it
 */
function runPpsCron(
    array   $orderRows,
    array   $responses = [],
    array   $failingKeys = [],
    ?string $trackAfterChain = '3SREAL123'
): array {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('debug')->andReturnNull();
    $logger->shouldReceive('notice')->andReturnNull();
    $logger->shouldReceive('warning')->andReturnNull();

    // The selector has its own test; here it is stubbed so these cases are about the per-key loop.
    $calls = new class {
        public array $fulfilment = [];
        public array $options    = [];
        public array $orderRow   = [];
    };

    $magentoOrders = Mockery::mock(Magento\Sales\Model\ResourceModel\Order\Collection::class);
    foreach (['addFieldToSelect', 'addAttributeToFilter', 'setPageSize', 'setOrder', 'addFieldToFilter'] as $fluent) {
        $magentoOrders->shouldReceive($fluent)->andReturnSelf();
    }
    $magentoOrders->shouldReceive('getData')->andReturn($orderRows);

    // What the order looks like *after* the chain: $trackNumber is what its MyParcel track ended up
    // holding, or null for an order the chain could give no track at all.
    $track = Mockery::mock(Magento\Sales\Model\Order\Shipment\Track::class);
    $track->shouldReceive('getCarrierCode')->andReturn(MyParcelNL\Magento\Model\Carrier\Carrier::CODE);
    $track->shouldReceive('getTrackNumber')->andReturn((string) $trackAfterChain);

    $shippableOrder = Mockery::mock(Order::class);
    $shippableOrder->shouldReceive('loadByIncrementId')->andReturnSelf();
    $shippableOrder->shouldReceive('canShip')->andReturn(true);
    $shippableOrder->shouldReceive('getId')->andReturn(1);
    $shippableOrder->shouldReceive('getIncrementId')->andReturn('100000001');
    $shippableOrder->shouldReceive('getTracksCollection')
        ->andReturn(null === $trackAfterChain ? [] : [$track]);
    $shippableOrder->shouldReceive('setData')->andReturnUsing(
        static function (string $key, $value) use ($shippableOrder, $calls) {
            $calls->orderRow[$key] = $value;

            return $shippableOrder;
        }
    );

    $orderResource = Mockery::mock(Magento\Sales\Model\ResourceModel\Order::class);
    $orderResource->shouldReceive('save')->andReturnSelf();

    $objectManager = Mockery::mock(Magento\Framework\ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')
        ->with(MagentoCollection::PATH_MODEL_ORDER_COLLECTION)
        ->andReturn($magentoOrders);
    $objectManager->shouldReceive('get')
        ->with(ShipmentApiProvider::class)
        ->andReturn(new ShipmentApiProvider(updateStatusConfig(), createUserAgent()));
    $objectManager->shouldReceive('create')
        ->with('Magento\Sales\Model\Order')
        ->andReturn($shippableOrder);

    $orderCollection = Mockery::mock(MagentoOrderCollection::class);
    foreach (['setOrderCollection', 'setNewMagentoShipment', 'setMagentoTrack', 'updateMagentoTrack'] as $fluent) {
        $orderCollection->shouldReceive($fluent)->andReturnSelf();
    }
    $orderCollection->shouldReceive('setOption')->andReturnUsing(
        static function (string $option, $value) use ($orderCollection, $calls) {
            $calls->options[$option] = $value;

            return $orderCollection;
        }
    );
    $orderCollection->shouldReceive('setFulfilmentTrackData')
        ->andReturnUsing(static function (array $fulfilment) use ($orderCollection, $calls) {
            $calls->fulfilment = $fulfilment;

            return $orderCollection;
        });

    $cron = newInstanceWithoutConstructor(RecordingUpdateStatus::class);
    $cron->orderRows = $orderRows;
    setPrivateProperty($cron, 'objectManager', $objectManager);
    setPrivateProperty($cron, 'config', updateStatusConfig());
    setPrivateProperty($cron, 'orderCollection', $orderCollection);
    setPrivateProperty($cron, 'orderResource', $orderResource);
    $cron->responses   = $responses;
    $cron->failingKeys = $failingKeys;

    invokePrivateMethod($cron, 'updateStatusPPS');

    return [$cron, $orderCollection, $calls];
}

it('issues one query per distinct API key', function () {
    [$cron] = runPpsCron([
        ['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1],
        ['increment_id' => '100000002', 'entity_id' => 2, 'store_id' => 1],
        ['increment_id' => '100000003', 'entity_id' => 3, 'store_id' => 2],
    ]);

    expect($cron->queriedKeys)->toBe(['KEY_A', 'KEY_B']);
});

it('never polls a store that has no API key of its own', function () {
    // Skipped, never lent another store's key — which would read one account's orders under
    // another account's credentials.
    [$cron] = runPpsCron([
        ['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1],
        ['increment_id' => '100000009', 'entity_id' => 9, 'store_id' => 3],
    ]);

    expect($cron->queriedKeys)->toBe(['KEY_A']);
});

it('keeps updating the other accounts when one account fails', function () {
    [$cron, $orderCollection] = runPpsCron(
        [
            ['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1],
            ['increment_id' => '100000003', 'entity_id' => 3, 'store_id' => 2],
        ],
        ['KEY_B' => new OrderCollection([shippedApiOrder('100000003')])],
        ['KEY_A']
    );

    expect($cron->queriedKeys)->toBe(['KEY_A', 'KEY_B']);
    $orderCollection->shouldHaveReceived('setNewMagentoShipment');
});

it('matches each account response only against that account\'s own orders', function () {
    // KEY_A's account answering with store 2's order must not update it: the order belongs to the
    // other account, and a shared id list would let the response cross over.
    [$cron, $orderCollection] = runPpsCron(
        [
            ['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1],
            ['increment_id' => '100000003', 'entity_id' => 3, 'store_id' => 2],
        ],
        ['KEY_A' => new OrderCollection([shippedApiOrder('100000003')])]
    );

    expect($cron->queriedKeys)->toBe(['KEY_A', 'KEY_B']);
    $orderCollection->shouldNotHaveReceived('setNewMagentoShipment');
});

it('hands the barcode and the MyParcel shipment id to the collection', function () {
    // A PPS track has no myparcel_consignment_id, so updateMagentoTrack() cannot refresh it. Both
    // facts have to travel from the fulfilment response or the track keeps its placeholder.
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([shippedApiOrder('100000001', '3SMYPA123', 4242)])]
    );

    expect($calls->fulfilment)->toBe(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);
});

it('still carries the barcode when the response names no shipment id', function () {
    // order_shipments passes through the SDK as a raw array, so the id is read defensively.
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([shippedApiOrder('100000001', '3SMYPA123')])]
    );

    expect($calls->fulfilment)->toBe(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => null]]]);
});

it('leaves an order the backoffice has not shipped yet completely alone', function () {
    // It used to be recorded as done before this check, so it still reached the collection below
    // and was given a Magento shipment and a placeholder track it could never come back from.
    [, $orderCollection, $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([conceptApiOrder('100000001')])]
    );

    expect($calls->fulfilment)->toBe([]);
    $orderCollection->shouldNotHaveReceived('setNewMagentoShipment');
});

it('writes the barcode to the order row when the chain could give it no track', function () {
    // No msi-source, or no shipment Magento would make. canShip() cannot tell us that in advance —
    // createMagentoShipment() rolls its quantity change back on failure, so canShip() stays true —
    // which is why the fallback is decided by what the chain produced.
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([shippedApiOrder('100000001', '3SMYPA123')])],
        [],
        null
    );

    expect($calls->orderRow)->toBe(['track_number' => json_encode(['3SMYPA123'])]);
});

it('leaves the order row alone once the track carries the barcode', function () {
    // The track is where a barcode belongs: it is the only place a track & trace link can be stored.
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([shippedApiOrder('100000001', '3SMYPA123')])],
        [],
        '3SMYPA123'
    );

    expect($calls->orderRow)->toBe([]);
});

it('does not write the order row for a track still holding a placeholder', function () {
    // A placeholder is not a barcode, so this order is not finished and must stay in scope rather
    // than be settled on the order row.
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([shippedApiOrder('100000001', '3SMYPA123')])],
        [],
        MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace::VALUE_EMPTY
    );

    expect($calls->orderRow)->toBe(['track_number' => json_encode(['3SMYPA123'])]);
});

it('never lets the cron add a second track to a shipment that has one', function () {
    // The default it used to inherit is a mass action's, and already-tracked orders reach
    // setMagentoTrack() now that the canShip() pre-check is gone.
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([shippedApiOrder('100000001')])]
    );

    expect($calls->options)->toBe(['create_track_if_one_already_exist' => false]);
});

/*
 * A fulfilment order can hold several shipments, all created in one run: separate shipments side by
 * side are one order_shipments entry each. Reading only the first is what let a single barcode
 * through. A multicollo is one entry instead, and its colli are expanded from the shipment
 * API by updateMagentoTrack(), so they are tested there.
 */

it('carries every shipped shipment of one fulfilment order', function () {
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([
            apiOrderWithShipments('100000001', [
                ['status' => ShipmentStatus::PRINTED_MINIMUM, 'barcode' => '3SA', 'id' => 1],
                ['status' => ShipmentStatus::PRINTED_MINIMUM, 'barcode' => '3SB', 'id' => 2],
            ]),
        ])]
    );

    expect($calls->fulfilment)->toBe(['100000001' => [
        ['barcode' => '3SA', 'shipmentId' => 1],
        ['barcode' => '3SB', 'shipmentId' => 2],
    ]]);
});

it('leaves a concept behind while carrying its shipped siblings', function () {
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([
            apiOrderWithShipments('100000001', [
                ['status' => ShipmentStatus::CONCEPT, 'barcode' => '3SA', 'id' => 1],
                ['status' => ShipmentStatus::PRINTED_MINIMUM, 'barcode' => '3SB', 'id' => 2],
            ]),
        ])]
    );

    // Reading order_shipments[0] alone skipped this whole order: its first entry is a concept.
    expect($calls->fulfilment)->toBe(['100000001' => [['barcode' => '3SB', 'shipmentId' => 2]]]);
});

it('ignores a cancelled shipment among the shipped ones', function () {
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([
            apiOrderWithShipments('100000001', [
                ['status' => ShipmentStatus::PRINTED_MINIMUM, 'barcode' => '3SA', 'id' => 1],
                ['status' => ShipmentStatus::CANCELLED, 'barcode' => '3SB', 'id' => 2],
            ]),
        ])]
    );

    expect($calls->fulfilment)->toBe(['100000001' => [['barcode' => '3SA', 'shipmentId' => 1]]]);
});

it('writes every barcode to the order row when the chain could give it no track', function () {
    [, , $calls] = runPpsCron(
        [['increment_id' => '100000001', 'entity_id' => 1, 'store_id' => 1]],
        ['KEY_A' => new OrderCollection([
            apiOrderWithShipments('100000001', [
                ['status' => ShipmentStatus::PRINTED_MINIMUM, 'barcode' => '3SA', 'id' => 1],
                ['status' => ShipmentStatus::PRINTED_MINIMUM, 'barcode' => '3SB', 'id' => 2],
            ]),
        ])],
        [],
        null
    );

    expect($calls->orderRow['track_number'] ?? null)->toBe(json_encode(['3SA', '3SB']));
});
