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
 * Builds a TrackTraceHolder bypassing its constructor — which reaches for a
 * live \Magento\Framework\App\ObjectManager::getInstance() through
 * `new DefaultOptions($order)` — then reflectively sets only the private
 * properties the test needs (see createConvertibleTrackTraceHolder() below
 * for the handful of behaviours that require the real constructor instead).
 * Anything left unset stays uninitialized, which is fine as long as the
 * method under test never touches it.
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
 * Builds a TrackTraceHolder through its real public constructor and drives
 * it through the real public convertDataFromMagentoToApi(), for the Phase 1
 * behaviours that live inline in that method rather than in an isolatable
 * private one (carrier-override pickup clearing, API key resolution).
 *
 * $checkoutDeliveryOptions becomes the order's saved `myparcel_delivery_options`
 * JSON — it must include a `deliveryType` key, or the SDK's
 * DeliveryOptionsAdapterFactory can't recognize the shape and throws. The
 * address is fixed to a non-NL, non-ROW destination (Germany) so age-check
 * and customs-declaration logic — covered by their own test files — never
 * activate here.
 *
 * $apiKeyStoreId makes only that store resolve to the key, leaving every
 * other store with none; null (the default) has every store resolve to it,
 * as an inherited default-scope value does. Pass it to exercise which store
 * the lookup consults — not Magento's own default/website/store fallback,
 * which createConfig() deliberately does not model.
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
 * Stubs the static \Magento\Framework\App\ObjectManager::getInstance() chain
 * that TrackTraceHolder::getAttributeValue() (private) and
 * ShipmentOptions::getAttributeValue() (static) use for the classification /
 * age-check EAV lookups — always the static singleton, never the instance
 * ObjectManager injected into the class under test. Both DB round-trips
 * (attribute id, then value) return $value; callers only ever use the second.
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
