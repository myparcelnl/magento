<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;

/**
 * Builds a TrackTraceHolder past its constructor, which reaches for a live
 * ObjectManager. Sets only the private properties the test names; anything
 * else stays uninitialized.
 */
function createTrackTraceHolder(array $properties = []): TrackTraceHolder
{
    $holder = newInstanceWithoutConstructor(TrackTraceHolder::class);

    foreach ($properties as $property => $value) {
        setPrivateProperty($holder, $property, $value);
    }

    return $holder;
}

/**
 * Drives the real constructor and the real convertDataFromMagentoToApi(),
 * for the behaviours that live inline in that method.
 *
 * $checkoutDeliveryOptions must carry a `deliveryType` key, or
 * DeliveryOptionsFactory throws. The address is fixed to Germany, so
 * age-check and customs logic never activate here. $apiKeyStoreId null means
 * every store resolves to the key, as an inherited default does; pass one to
 * make only that store resolve.
 *
 * @return array{0: TrackTraceHolder, 1: \Magento\Sales\Model\Order\Shipment\Track}
 */
function createConvertibleTrackTraceHolder(
    array $checkoutDeliveryOptions,
    ?string $apiKey = 'test-api-key',
    int $orderStoreId = 1,
    ?int $apiKeyStoreId = null
): array {
    $config = null === $apiKeyStoreId
        ? createConfig(['api/key' => $apiKey])
        : createConfig([], [], [$apiKeyStoreId => ['api/key' => $apiKey]]);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn($config);
    $objectManager->shouldReceive('get')->with(JsonSerializer::class)->andReturn(new JsonSerializer());
    $objectManager->shouldReceive('get')->with(Weight::class)->andReturn(new Weight($config));
    $objectManager->shouldReceive('create')
        ->with('Magento\Framework\Message\ManagerInterface')
        ->andReturn(Mockery::mock(ManagerInterface::class));

    // DefaultOptions, constructed for real inside TrackTraceHolder's own
    // constructor, reaches for the static ObjectManager singleton rather
    // than the instance injected above — both must resolve Config::class.
    $staticObjectManager = Mockery::mock(ObjectManagerInterface::class);
    $staticObjectManager->shouldReceive('get')->with(Config::class)->andReturn($config);
    ObjectManager::setInstance($staticObjectManager);

    $address = createAddress(['getCountryId' => 'DE', 'street' => 'Musterstrasse 1']);
    $order   = createOrder([
        'getShippingAddress' => $address,
        'getStoreId'         => $orderStoreId,
        'deliveryOptions'    => json_encode($checkoutDeliveryOptions),
    ]);
    $shipment = createShipment(['getShippingAddress' => $address, 'getOrder' => $order]);
    $track    = createShipmentTrack(['getShipment' => $shipment]);

    return [new TrackTraceHolder($objectManager, $order), $track];
}

/**
 * Stubs the static ObjectManager the EAV lookups resolve through — the static
 * singleton, never the instance one injected into the class under test. Both
 * round-trips return $value; callers only use the second.
 */
function mockAttributeValueLookup(string $value): void
{
    $select = Mockery::mock();
    $select->shouldReceive('from')->andReturnSelf();
    $select->shouldReceive('where')->andReturnSelf();

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('select')->andReturn($select);
    $connection->shouldReceive('fetchOne')->andReturn($value);

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(fn (string $name) => $name);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(ResourceConnection::class)->andReturn($resource);

    ObjectManager::setInstance($objectManager);
}
