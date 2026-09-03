<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Model\Shipment\CustomsDeclarationBuilder;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesMoney;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * The v11 customs stack is a second builder, unrelated to the Order v1 one the
 * fulfilment path uses (DR-22). ConsignmentEncode::DEFAULT_CURRENCY named the
 * currency before; it is one of the classes beta.22 removed, so the same value
 * is now read off the generated money model.
 *
 * Product data (HS code, country of manufacture) is fetched in two batch
 * queries keyed by product id, never per item — that is what the mocks model.
 */
function createCustomsDeclarationBuilder(string $classification = '61', int $productId = 99): CustomsDeclarationBuilder
{
    $select = Mockery::mock();
    $select->shouldReceive('from')->andReturnSelf();
    $select->shouldReceive('where')->andReturnSelf();

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('select')->andReturn($select);
    $connection->shouldReceive('fetchOne')->andReturn('137'); // the classification attribute id
    $connection->shouldReceive('fetchPairs')->andReturn([$productId => $classification]);

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(fn (string $name) => $name);

    $product = Mockery::mock();
    $product->shouldReceive('getId')->andReturn($productId);
    $product->shouldReceive('getCountryOfManufacture')->andReturn('CN');

    $productCollection = Mockery::mock(ProductCollection::class);
    $productCollection->shouldReceive('addIdFilter')->andReturnSelf();
    $productCollection->shouldReceive('addAttributeToSelect')->andReturnSelf();
    $productCollection->shouldReceive('getItems')->andReturn([$product]);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(ResourceConnection::class)->andReturn($resource);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($productCollection);

    $config = createConfig(['print/weight_indication' => 'gram']);

    return new CustomsDeclarationBuilder($objectManager, $config, new Weight($config));
}

/** @return array{0: Shipment, 1: array} */
function buildCustomsFor(array $items, string $classification = '61'): array
{
    $magentoShipment = createShipment(['items' => $items]);
    $declaration     = createCustomsDeclarationBuilder($classification)->build($magentoShipment, 1000, '100000001');
    $shipment        = (new Shipment())->setCustomsDeclaration($declaration);

    return [$shipment, builtShipmentCustomsItems($shipment)];
}

it('maps every customs field from the shipment item', function () {
    $item = createShipmentItem([
        'name'       => 'Widget',
        'qty'        => 1,
        'weight'     => 250.0,
        'price'      => 12.34,
        'product_id' => 99,
    ]);

    [, $items] = buildCustomsFor([$item]);

    expect($items[0]->getDescription())->toBe('Widget');
    expect($items[0]->getAmount())->toBe(1);
    expect($items[0]->getWeight())->toBe(250);
    expect(customsItemValue($items[0]))->toBe(['amount' => 1234, 'currency' => RefTypesMoney::CURRENCY_EUR]);
    expect($items[0]->getClassification())->toBe('61');
    expect($items[0]->getCountry())->toBe('CN');
});

it('adds each shipment item to the consignment exactly once', function () {
    // convertDataForCdCountry() looped the shipment items twice — once via
    // getData('items'), once via getItems() — and added every item on both
    // passes. One loop now, so this is no longer a ->todo().
    $item = createShipmentItem(['name' => 'Widget', 'qty' => 1, 'weight' => 250.0, 'price' => 12.34, 'product_id' => 99]);

    [, $items] = buildCustomsFor([$item]);

    expect($items)->toHaveCount(1);
});

it('truncates a description the API would refuse rather than throwing', function () {
    // MyParcelCustomsItem truncated at 50 silently; the generated setter throws.
    $item = createShipmentItem([
        'name'       => str_repeat('a', 80),
        'qty'        => 1,
        'weight'     => 250.0,
        'price'      => 1.0,
        'product_id' => 99,
    ]);

    [, $items] = buildCustomsFor([$item]);

    expect($items[0]->getDescription())->toBe(str_repeat('a', 50));
});

/**
 * An HS code is a numeric string of up to 18 characters that may carry dots (6109.10 is cotton
 * t-shirts). It used to be stored in an int column and read with an (int) cast, so a leading zero
 * was lost, a dot was impossible, and anything past 2147483647 was clamped to it.
 */
function classificationOf(string $stored): string
{
    $item = createShipmentItem(['name' => 'Widget', 'qty' => 1, 'weight' => 250.0, 'price' => 1.0, 'product_id' => 99]);

    [, $items] = buildCustomsFor([$item], $stored);

    return $items[0]->getClassification();
}

it('keeps the leading zero of an HS code', function () {
    expect(classificationOf('0901'))->toBe('0901');
});

it('keeps the dot in a subheading HS code', function () {
    expect(classificationOf('6109.10'))->toBe('6109.10');
});

it('carries an HS code of the full eighteen characters', function () {
    // The previous cap was 10, which is ours rather than the API's — the Core API types this field
    // as a plain string with no maximum.
    expect(classificationOf('610910.0010.123456'))->toBe('610910.0010.123456');
});
