<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Service\Export\OrderCustomsDeclarationBuilder;
use MyParcelNL\Magento\Service\Weight;

/**
 * The Order v1 customs items of an order holding these rows: each shipped row at its weight and at the
 * price paid in the order's currency. $attributes (attribute => product id => value) and $countries
 * are what the catalog answers.
 */
function customsItemsOf(
    array  $orderItems,
    array  $config = [],
    array  $attributes = ['classification' => [7 => '6109.10']],
    array  $countries = [7 => 'CN'],
    string $homeCountry = 'NL'
): array
{
    $order = createOrder([
        'getItems'         => $orderItems,
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig($config + ['print/weight_indication' => 'gram']);
    $objectManager = customsObjectManager($attributes, $countries, $config, $homeCountry);

    return (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;
}

/** One row of product 7. */
function orderV1CustomsItems(
    array  $productData,
    array  $config = [],
    array  $itemData = [],
    string $homeCountry = 'NL',
    array  $attributes = []
): array
{
    $item = createOrderItem(['product_id' => '7'] + $itemData, ['id' => 7] + $productData);

    return customsItemsOf(
        [$item],
        $config,
        ['classification' => [7 => '6109.10']] + $attributes,
        [7 => $productData['country_of_manufacture'] ?? null],
        $homeCountry
    );
}

it('takes the account\'s home country when no country of origin is set', function () {
    expect(orderV1CustomsItems([], [], [], 'BE')[0]->getCountry())->toBe('BE');
});

it('takes the country of origin from the product when it has one', function () {
    expect(orderV1CustomsItems(['country_of_manufacture' => 'CN'])[0]->getCountry())->toBe('CN');
});

it('falls back to the configured country of origin, not a hardcoded NL', function () {
    expect(orderV1CustomsItems([], ['print/country_of_origin' => 'BE'])[0]->getCountry())->toBe('BE');
});

it('declares weight and the price paid at line level, multiplied by the quantity', function () {
    // The catalog price differs from what the customer paid: the declaration takes the paid price.
    $items = orderV1CustomsItems(
        ['country_of_manufacture' => 'CN', 'price' => 99.0],
        [],
        ['qty_ordered' => 3, 'price' => 10.0, 'weight' => 100.0]
    );

    expect($items[0]->getWeight())->toBe(300)
        ->and($items[0]->getItemValue()['amount'])->toBe(3000);
});

it('declares the quantity ordered of an unshipped row as the database stores it', function () {
    $items = orderV1CustomsItems(['country_of_manufacture' => 'CN'], [], ['qty_shipped' => '0.0000', 'qty_ordered' => '3.0000']);

    expect($items[0]->getAmount())->toBe(3);
});

it('declares at least one piece for a fractional quantity, which the API would refuse as zero', function () {
    $items = orderV1CustomsItems(['country_of_manufacture' => 'CN'], [], ['qty_ordered' => 0.5, 'price' => 10.0]);

    expect($items[0]->getAmount())->toBe(1);
});

it('leaves a virtual product out of the declaration', function () {
    $parent  = createOrderItem(['item_id' => 1, 'product_type' => 'configurable', 'name' => 'Shirt'], ['id' => 7]);
    $child   = createOrderItem(['item_id' => 2, 'parent_item' => $parent, 'is_dummy' => true, 'name' => 'Shirt, red'], ['id' => 7]);
    $virtual = createOrderItem(['item_id' => 3, 'is_virtual' => true, 'name' => 'Gift wrap'], ['id' => 7]);
    $items   = customsItemsOf([$parent, $child, $virtual]);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getDescription())->toBe('Shirt, red');
});

/*
 * A configurable product is ordered as a parent item and a variant item. Product 4 is the parent,
 * 9 the variant.
 */

function orderV1ConfigurableCustomsItems(
    array $attributes,
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
        ['item_id' => 315, 'product_id' => 9, 'product_type' => 'simple', 'parent_item' => $parent, 'name' => 'Caterham Red', 'weight' => 100.0] + $childQty,
        ['id' => 9]
    );

    return customsItemsOf([$parent, $child], [], $attributes, $countries);
}

it('declares a configurable product once, as the variant, and falls back to the parent', function (
    array $classifications,
    array $countries,
    array $expected
) {
    $items = orderV1ConfigurableCustomsItems(['classification' => $classifications], $countries);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getDescription())->toBe('Caterham Red')
        ->and([$items[0]->getClassification(), $items[0]->getCountry()])->toBe($expected);
})->with([
    'the variant has customs data' => [[4 => '2147483647', 9 => '0090902341'], [4 => 'CN', 9 => 'AQ'], ['0090902341', 'AQ']],
    'the variant has none'         => [[4 => '6109.10'], [4 => 'CN', 9 => null], ['610910', 'CN']],
]);

it('takes a configurable line\'s quantity from the parent, which alone records what shipped', function () {
    $items = orderV1ConfigurableCustomsItems(
        ['classification' => [9 => '6109.10']],
        [9 => 'CN'],
        ['qty_ordered' => 3, 'qty_shipped' => 1],
        ['qty_ordered' => 3, 'qty_shipped' => 0]
    );

    expect($items[0]->getAmount())->toBe(1)
        ->and($items[0]->getWeight())->toBe(100)
        ->and($items[0]->getItemValue()['amount'])->toBe(1000);
});
it('declares the children of a bundle shipped separately, not the bundle', function () {
    $bundle = createOrderItem(['item_id' => 1, 'product_type' => 'bundle', 'is_dummy' => true, 'name' => 'Gift set'], ['id' => 7]);
    $mug    = createOrderItem(['item_id' => 2, 'parent_item' => $bundle, 'name' => 'Mug'], ['id' => 7]);
    $plate  = createOrderItem(['item_id' => 3, 'parent_item' => $bundle, 'name' => 'Plate'], ['id' => 7]);

    expect(array_map(static fn ($item) => $item->getDescription(), customsItemsOf([$bundle, $mug, $plate])))
        ->toBe(['Mug', 'Plate']);
});

it('declares the row of a product that was deleted from the catalog', function () {
    // The label path declares it too: the parcel still holds the item.
    $item  = createOrderItem(['product' => null, 'product_id' => '7', 'name' => 'Old mug', 'weight' => 0.3]);
    $items = customsItemsOf([$item], ['print/weight_indication' => 'kilo', 'print/country_of_origin' => 'NL'], [], []);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getDescription())->toBe('Old mug')
        ->and($items[0]->getWeight())->toBe(300)
        ->and($items[0]->getCountry())->toBe('NL');
});

it('sends the external product reference of a product that has one', function () {
    $items = orderV1CustomsItems([], [], [], 'NL', ['external_product_reference' => [7 => 'zonos-item-1']]);

    expect($items[0]->getExternalProductReference())->toBe('zonos-item-1');
});

it('refuses an external product reference above 64 characters and names the product', function () {
    orderV1CustomsItems([], [], ['name' => 'Widget', 'sku' => 'SKU-7'], 'NL', ['external_product_reference' => [7 => str_repeat('z', 65)]]);
})->throws(RuntimeException::class, 'customs item "Widget" (SKU-7): External product reference must be 64 characters or less');

it('sets no external product reference when the product has none', function () {
    expect(orderV1CustomsItems([])[0]->getExternalProductReference())->toBeNull();
});

it('takes a configurable line its external product reference from the variant, or else the parent', function (
    array $references,
    string $expected
) {
    $items = orderV1ConfigurableCustomsItems(['external_product_reference' => $references], [4 => 'CN', 9 => null]);

    expect($items[0]->getExternalProductReference())->toBe($expected);
})->with([
    'the variant has one' => [[4 => 'zonos-parent', 9 => 'zonos-variant'], 'zonos-variant'],
    'the variant has none' => [[4 => 'zonos-parent'], 'zonos-parent'],
]);
