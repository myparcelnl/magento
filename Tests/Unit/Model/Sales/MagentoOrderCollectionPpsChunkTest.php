<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Magento\Service\Weight;

/**
 * The PPS export chunks by print/export_chunk_size, the same setting the shipment export uses.
 *
 * Only the chunking is driven here. Sending a chunk ends in an HTTP call the SDK builds its own
 * cURL handle for, so exportFulfilmentChunk() is stubbed and what reaches it is what these cases
 * assert.
 *
 * @param array<int|string,int> $storeIdsByIncrementId order increment id => store id
 *
 * @return object{collection: MagentoOrderCollection, chunks: array} filled once setFulfilment() runs
 */
function ppsChunkCollection(array $storeIdsByIncrementId, $chunkSize = null): object
{
    $did = new class { public array $chunks = []; public $collection = null; };

    $config = createConfig(['print/export_chunk_size' => $chunkSize]);

    $apiProvider = Mockery::mock(ShipmentApiProvider::class);
    $apiProvider->shouldReceive('apiKeyForStore')
        ->andReturnUsing(static fn(int $storeId): string => 'key-for-store-' . $storeId);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Weight::class)->andReturn(new Weight($config));
    $objectManager->shouldReceive('get')->with(ShipmentApiProvider::class)->andReturn($apiProvider);

    $userAgent = Mockery::mock(UserAgent::class);
    $userAgent->shouldReceive('map')->andReturn([]);

    $orders = [];

    foreach ($storeIdsByIncrementId as $incrementId => $storeId) {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('getStoreId')->andReturn($storeId);
        $order->shouldReceive('getIncrementId')->andReturn((string) $incrementId);
        $orders[] = $order;
    }

    $collection = Mockery::mock(MagentoOrderCollection::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('exportFulfilmentChunk')->andReturnUsing(
        static function ($builder, array $chunk) use ($did): void {
            $did->chunks[] = array_map(static fn($order): string => $order->getIncrementId(), $chunk);
        }
    );

    setPrivateProperty($collection, 'config', $config);
    setPrivateProperty($collection, 'apiProvider', $apiProvider);
    setPrivateProperty($collection, 'objectManager', $objectManager);
    setPrivateProperty($collection, 'userAgent', $userAgent);
    $collection->setOrderCollection($orders);

    $did->collection = $collection;

    return $did;
}

it('sends one request per chunk instead of one per account', function () {
    $did = ppsChunkCollection(['1' => 1, '2' => 1, '3' => 1, '4' => 1, '5' => 1], 2);

    $did->collection->setFulfilment();

    expect($did->chunks)->toBe([['1', '2'], ['3', '4'], ['5']]);
});

it('never mixes two accounts into one request', function () {
    // The grouping is what keeps one account's failure from un-exporting another's, so a chunk that
    // spanned accounts would give that back.
    $did = ppsChunkCollection(['1' => 1, '2' => 2, '3' => 1, '4' => 2], 10);

    $did->collection->setFulfilment();

    expect($did->chunks)->toBe([['1', '3'], ['2', '4']]);
});

it('falls back to twenty per request when the setting is empty', function () {
    $did = ppsChunkCollection(array_fill_keys(range(1, 21), 1));

    $did->collection->setFulfilment();

    expect($did->chunks)->toHaveCount(2)
        ->and($did->chunks[0])->toHaveCount(20)
        ->and($did->chunks[1])->toHaveCount(1);
});

it('sends nothing at all for an empty selection', function () {
    $did = ppsChunkCollection([], 20);

    $did->collection->setFulfilment();

    expect($did->chunks)->toBeEmpty();
});
