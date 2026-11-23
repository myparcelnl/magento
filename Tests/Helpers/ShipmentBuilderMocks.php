<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use MyParcelNL\Magento\Service\Export\CustomsDeclarationBuilder;
use MyParcelNL\Magento\Service\Export\OrderShipmentOptions;
use MyParcelNL\Magento\Service\Export\RecipientFields;
use MyParcelNL\Magento\Service\Export\ShipmentBuilder;
use MyParcelNL\Magento\Service\Export\ShipmentValidator;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\ProductAttributes;
use MyParcelNL\Magento\Service\Weight;

/**
 * Builds a ShipmentBuilder past its constructor, which reaches for a live
 * ObjectManager. Sets only the private properties the test names; anything
 * else stays uninitialized.
 */
function createShipmentBuilder(array $properties = []): ShipmentBuilder
{
    return instanceWithProperties(ShipmentBuilder::class, $properties);
}

/**
 * Builds an OrderShipmentOptions past its constructor. Setting `deliveryOptions` and `resolved`
 * fills the two memoised answers, so a test can name only the question it is asking.
 */
function createOrderShipmentOptions(array $properties = []): OrderShipmentOptions
{
    return instanceWithProperties(OrderShipmentOptions::class, $properties);
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
    $objectManager->shouldReceive('get')->with(RecipientFields::class)->andReturn(new RecipientFields());
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
 * Create a ShipmentBuilder that runs the real constructor and the real build().
 *
 * $checkoutDeliveryOptions must carry a `deliveryType` key, or DeliveryOptionsFactory throws.
 * The address is in Germany, so age check and customs logic never activate. With $apiKeyStoreId
 * null, every store resolves to the key; pass one to make only that store resolve.
 *
 * @return array{0: ShipmentBuilder, 1: \Magento\Sales\Model\Order\Shipment\Track}
 */
function createConvertibleShipmentBuilder(
    array $checkoutDeliveryOptions,
    ?string $apiKey = 'test-api-key',
    int $orderStoreId = 1,
    ?int $apiKeyStoreId = null,
    array $shipmentOverrides = []
): array {
    $objectManager = mockExportObjectManager(createExportConfig($apiKey, $apiKeyStoreId));

    $address = createAddress(['getCountryId' => 'DE', 'street' => 'Musterstrasse 1']);
    $order   = createOrder([
        'getShippingAddress' => $address,
        'getStoreId'         => $orderStoreId,
        'deliveryOptions'    => json_encode($checkoutDeliveryOptions),
    ]);
    $shipment = createShipment(['getShippingAddress' => $address, 'getOrder' => $order] + $shipmentOverrides);
    $track    = createShipmentTrack(['getShipment' => $shipment]);

    return [new ShipmentBuilder($objectManager, $order), $track];
}

/**
 * Stub the static ObjectManager singleton that the product attribute reads resolve through, not the
 * instance injected into the class under test.
 *
 * Each filtered id comes back as a product that answers $value for every attribute. An empty string
 * is a product that says nothing: the "no opinion" tier.
 *
 * @param string|array<string,string|int> $value      one value for every attribute, or column => value
 *                                                    (column without `myparcel_`; a missing one is '')
 * @param array<class-string, mixed>      $alsoBind   extra bindings resolved from the same singleton
 */
function mockAttributeValueLookup($value, array $alsoBind = []): void
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
                $product->shouldReceive('getData')->andReturnUsing(
                    static function (string $key) use ($value) {
                        return is_array($value) ? (string) ($value[substr($key, strlen('myparcel_'))] ?? '') : $value;
                    }
                );
                $products[] = $product;
            }

            return $products;
        }
    );

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($collection);

    // The age check reads through the request's shared reader, so the singleton has to answer for it.
    $objectManager->shouldReceive('get')
                  ->with(ProductAttributes::class)
                  ->andReturn(new ProductAttributes($objectManager));

    // This helper owns the singleton, so mockLoggerFacade() cannot also be called. An order with no
    // resolvable insurance range logs a notice, and that has to land somewhere.
    $objectManager->shouldReceive('get')->with(LoggerInterface::class)
        ->andReturn(makePermissiveLogger());

    foreach ($alsoBind as $class => $instance) {
        $objectManager->shouldReceive('get')->with($class)->andReturn($instance);
    }

    ObjectManager::setInstance($objectManager);
}
