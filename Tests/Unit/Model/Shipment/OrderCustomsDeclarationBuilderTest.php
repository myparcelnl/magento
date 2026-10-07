<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Model\Shipment\OrderCustomsDeclarationBuilder;
use MyParcelNL\Magento\Service\Weight;

/** The PPS customs path: each line at product weight and at the price paid in the order's currency. */
function ppsCustomsItems(array $productData, array $config = [], array $itemData = [], string $homeCountry = 'NL'): array
{
    $item  = createOrderItem($itemData, ['id' => 7] + $productData);
    $order = createOrder([
        'getItems'         => [$item],
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig(['print/weight_indication' => 'gram'] + $config);
    $objectManager = customsObjectManager([7 => '6109.10'], [7 => $productData['country_of_manufacture'] ?? null], $config, $homeCountry);

    return (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;
}

it('takes the account\'s home country when no country of origin is set', function () {
    expect(ppsCustomsItems([], [], [], 'BE')[0]->getCountry())->toBe('BE');
});

it('takes the country of origin from the product when it has one', function () {
    expect(ppsCustomsItems(['country_of_manufacture' => 'CN'])[0]->getCountry())->toBe('CN');
});

it('falls back to the configured country of origin, not a hardcoded NL', function () {
    expect(ppsCustomsItems([], ['print/country_of_origin' => 'BE'])[0]->getCountry())->toBe('BE');
});

it('declares weight and the price paid at line level, multiplied by the quantity', function () {
    // The catalog price differs from what the customer paid: the declaration takes the paid price.
    $items = ppsCustomsItems(
        ['country_of_manufacture' => 'CN', 'weight' => 100.0, 'price' => 99.0],
        [],
        ['qty_ordered' => 3, 'price' => 10.0]
    );

    expect($items[0]->getWeight())->toBe(300)
        ->and($items[0]->getItemValue()['amount'])->toBe(3000);
});

it('declares at least one piece for a fractional quantity, which the API would refuse as zero', function () {
    $items = ppsCustomsItems(['country_of_manufacture' => 'CN'], [], ['qty_ordered' => 0.5, 'price' => 10.0]);

    expect($items[0]->getAmount())->toBe(1);
});

it('leaves a virtual product out of the declaration', function () {
    $parent  = createOrderItem(['item_id' => 1, 'product_type' => 'configurable'], ['id' => 7, 'name' => 'Shirt']);
    $child   = createOrderItem(['item_id' => 2, 'parent_item' => $parent, 'is_dummy' => true], ['id' => 7, 'name' => 'Shirt, red']);
    $virtual = createOrderItem(['item_id' => 3, 'is_virtual' => true], ['id' => 7, 'name' => 'Gift wrap']);
    $order   = createOrder([
        'getItems'         => [$parent, $child, $virtual],
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig(['print/weight_indication' => 'gram']);
    $objectManager = customsObjectManager([7 => '6109.10'], [7 => 'CN'], $config);
    $items         = (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;

    expect($items)->toHaveCount(1)
        ->and($items[0]->getDescription())->toBe('Shirt, red');
});

/*
 * A configurable product is ordered as a parent item and a variant item. Product 4 is the parent,
 * 9 the variant.
 */

function ppsConfigurableCustomsItems(
    array $classifications,
    array $countries,
    array $parentQty = [],
    array $childQty = []
): array
{
    $parent = createOrderItem(
        ['item_id' => 314, 'product_id' => 4, 'product_type' => 'configurable'] + $parentQty,
        ['id' => 4]
    );
    $child  = createOrderItem(
        ['item_id' => 315, 'product_id' => 9, 'product_type' => 'simple', 'parent_item' => $parent] + $childQty,
        ['id' => 9, 'name' => 'Caterham Red', 'weight' => 100.0, 'price' => 10.0]
    );
    $order  = createOrder([
        'getItems'         => [$parent, $child],
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig(['print/weight_indication' => 'gram']);
    $objectManager = customsObjectManager($classifications, $countries, $config);

    return (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;
}

it('declares a configurable product once, as the variant', function () {
    $items = ppsConfigurableCustomsItems([4 => '2147483647', 9 => '0090902341'], [4 => 'CN', 9 => 'AQ']);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getDescription())->toBe('Caterham Red')
        ->and($items[0]->getClassification())->toBe('0090902341')
        ->and($items[0]->getCountry())->toBe('AQ');
});

it('takes a configurable line\'s quantity from the parent, which alone records what shipped', function () {
    $items = ppsConfigurableCustomsItems(
        [9 => '6109.10'],
        [9 => 'CN'],
        ['qty_ordered' => 3, 'qty_shipped' => 1],
        ['qty_ordered' => 3, 'qty_shipped' => 0]
    );

    expect($items[0]->getAmount())->toBe(1)
        ->and($items[0]->getWeight())->toBe(100)
        ->and($items[0]->getItemValue()['amount'])->toBe(1000);
});

it('falls back to the configurable parent where the variant has no customs data', function () {
    $items = ppsConfigurableCustomsItems([4 => '6109.10'], [4 => 'CN', 9 => null]);

    expect($items[0]->getClassification())->toBe('610910')
        ->and($items[0]->getCountry())->toBe('CN');
});

it('declares the children of a bundle shipped separately, not the bundle', function () {
    $bundle = createOrderItem(['item_id' => 1, 'product_type' => 'bundle', 'is_dummy' => true], ['id' => 7, 'name' => 'Gift set']);
    $mug    = createOrderItem(['item_id' => 2, 'parent_item' => $bundle], ['id' => 7, 'name' => 'Mug']);
    $plate  = createOrderItem(['item_id' => 3, 'parent_item' => $bundle], ['id' => 7, 'name' => 'Plate']);
    $order  = createOrder([
        'getItems'         => [$bundle, $mug, $plate],
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig(['print/weight_indication' => 'gram']);
    $objectManager = customsObjectManager([7 => '6109.10'], [7 => 'CN'], $config);
    $items         = (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;

    expect(array_map(static fn ($item) => $item->getDescription(), $items))->toBe(['Mug', 'Plate']);
});
