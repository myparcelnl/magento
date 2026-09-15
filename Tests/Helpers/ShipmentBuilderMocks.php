<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use MyParcelNL\Magento\Model\Shipment\CustomsDeclarationBuilder;
use MyParcelNL\Magento\Model\Shipment\OrderShipmentOptions;
use MyParcelNL\Magento\Model\Shipment\ShipmentBuilder;
use MyParcelNL\Magento\Model\Shipment\ShipmentValidator;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;

/**
 * Builds a ShipmentBuilder past its constructor, which reaches for a live
 * ObjectManager. Sets only the private properties the test names; anything
 * else stays uninitialized.
 */
function createShipmentBuilder(array $properties = []): ShipmentBuilder
{
    $builder = newInstanceWithoutConstructor(ShipmentBuilder::class);

    foreach ($properties as $property => $value) {
        setPrivateProperty($builder, $property, $value);
    }

    return $builder;
}

/**
 * Builds an OrderShipmentOptions past its constructor. Setting `deliveryOptions` and `resolved`
 * fills the two memoised answers, so a test can name only the question it is asking.
 */
function createOrderShipmentOptions(array $properties = []): OrderShipmentOptions
{
    $options = newInstanceWithoutConstructor(OrderShipmentOptions::class);

    foreach ($properties as $property => $value) {
        setPrivateProperty($options, $property, $value);
    }

    return $options;
}

/**
 * Config as the export builders read it: one key for every store, or a key only store
 * $apiKeyStoreId resolves to.
 */
function createExportConfig(?string $apiKey, ?int $apiKeyStoreId = null, array $extraValues = []): Config
{
    return null === $apiKeyStoreId
        ? createConfig(['api/key' => $apiKey] + $extraValues)
        : createConfig($extraValues, [], [$apiKeyStoreId => ['api/key' => $apiKey]]);
}

/**
 * The object manager both export builders resolve their collaborators through, and the static
 * singleton their DefaultOptions reaches for. Returned so a caller can add its own expectations.
 */
function mockExportObjectManager(Config $config): ObjectManagerInterface
{
    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn($config);
    $objectManager->shouldReceive('get')->with(JsonSerializer::class)->andReturn(new JsonSerializer());
    $objectManager->shouldReceive('get')->with(Weight::class)->andReturn(new Weight($config));
    $objectManager->shouldReceive('get')->with(ShipmentApiProvider::class)
        ->andReturn(new ShipmentApiProvider($config, createUserAgent()));
    $objectManager->shouldReceive('get')->with(ShipmentValidator::class)->andReturn(new ShipmentValidator());
    // Never reached from a German address (customs is ROW-only), but the constructor fetches it.
    $objectManager->shouldReceive('get')->with(CustomsDeclarationBuilder::class)
        ->andReturn(new CustomsDeclarationBuilder($objectManager, $config, new Weight($config)));
    $objectManager->shouldReceive('create')
        ->with('Magento\Framework\Message\ManagerInterface')
        ->andReturn(Mockery::mock(ManagerInterface::class));

    // DefaultOptions, constructed for real inside the builders, reaches for the static
    // ObjectManager singleton rather than the instance injected above: for Config, and for the
    // product age check attribute, which answers "no opinion" here.
    mockAttributeValueLookup('', [Config::class => $config]);

    return $objectManager;
}

/**
 * Drives the real constructor and the real build(), for the behaviours that
 * live inline in that method.
 *
 * $checkoutDeliveryOptions must carry a `deliveryType` key, or
 * DeliveryOptionsFactory throws. The address is fixed to Germany, so
 * age-check and customs logic never activate here. $apiKeyStoreId null means
 * every store resolves to the key, as an inherited default does; pass one to
 * make only that store resolve.
 *
 * @return array{0: ShipmentBuilder, 1: \Magento\Sales\Model\Order\Shipment\Track}
 */
function createConvertibleShipmentBuilder(
    array $checkoutDeliveryOptions,
    ?string $apiKey = 'test-api-key',
    int $orderStoreId = 1,
    ?int $apiKeyStoreId = null
): array {
    $objectManager = mockExportObjectManager(createExportConfig($apiKey, $apiKeyStoreId));

    $address = createAddress(['getCountryId' => 'DE', 'street' => 'Musterstrasse 1']);
    $order   = createOrder([
        'getShippingAddress' => $address,
        'getStoreId'         => $orderStoreId,
        'deliveryOptions'    => json_encode($checkoutDeliveryOptions),
    ]);
    $shipment = createShipment(['getShippingAddress' => $address, 'getOrder' => $order]);
    $track    = createShipmentTrack(['getShipment' => $shipment]);

    return [new ShipmentBuilder($objectManager, $order), $track];
}

/**
 * Stubs the static ObjectManager the product attribute reads resolve through — the static
 * singleton, never the instance one injected into the class under test.
 *
 * Whatever ids ProductAttributes filters on come back as products, each answering $value for every
 * attribute. An empty string is a product that says nothing, which is how the "no opinion" tier is
 * exercised.
 *
 * @param array<class-string, mixed> $alsoBind extra bindings resolved from the same singleton
 */
function mockAttributeValueLookup(string $value, array $alsoBind = []): void
{
    $filtered = [];

    $collection = Mockery::mock(ProductCollection::class);
    $collection->shouldReceive('addIdFilter')->andReturnUsing(
        static function (array $ids) use (&$filtered, $collection) {
            $filtered = $ids;

            return $collection;
        }
    );
    $collection->shouldReceive('addAttributeToSelect')->andReturnSelf();
    $collection->shouldReceive('getItems')->andReturnUsing(
        static function () use (&$filtered, $value): array {
            $products = [];

            foreach ($filtered as $id) {
                $product = Mockery::mock();
                $product->shouldReceive('getId')->andReturn($id);
                $product->shouldReceive('getData')->andReturn($value);
                $products[] = $product;
            }

            return $products;
        }
    );

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($collection);

    // This helper owns the singleton, so mockLoggerFacade() cannot also be called. An order with no
    // resolvable insurance range logs a notice, and that has to land somewhere.
    $objectManager->shouldReceive('get')->with(LoggerInterface::class)
        ->andReturn(Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing());

    foreach ($alsoBind as $class => $instance) {
        $objectManager->shouldReceive('get')->with($class)->andReturn($instance);
    }

    ObjectManager::setInstance($objectManager);
}
