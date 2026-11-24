<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Service\Export\OrderCustomsDeclarationBuilder;
use MyParcelNL\Magento\Service\Weight;

/**
 * The PPS customs items of an order holding these rows: each shipped row at its weight and at the price
 * paid in the order's currency. $hsCodes and $countries are what the catalog answers, by product id.
 */
function customsItemsOf(array $orderItems, array $config = [], array $hsCodes = [7 => '6109.10'], array $countries = [7 => 'CN']): array
{
    $order = createOrder([
        'getItems'         => $orderItems,
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig($config + ['print/weight_indication' => 'gram']);
    $objectManager = customsObjectManager($hsCodes, $countries, $config);

    return (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;
}

/** One row of product 7. */
function ppsCustomsItems(array $productData, array $config = [], array $itemData = []): array
{
    $item = createOrderItem(['product_id' => '7'] + $itemData, ['id' => 7] + $productData);

    return customsItemsOf([$item], $config, [7 => '6109.10'], [7 => $productData['country_of_manufacture'] ?? null]);
}

it('takes the country of origin from the product when it has one', function () {
    expect(ppsCustomsItems(['country_of_manufacture' => 'CN'])[0]->getCountry())->toBe('CN');
});

it('falls back to the configured country of origin, not a hardcoded NL', function () {
    expect(ppsCustomsItems([], ['print/country_of_origin' => 'BE'])[0]->getCountry())->toBe('BE');
});

it('declares weight and the price paid at line level, multiplied by the quantity', function () {
    // The catalog price differs from what the customer paid: the declaration takes the paid price.
    $items = ppsCustomsItems(
        ['country_of_manufacture' => 'CN', 'price' => 99.0],
        [],
        ['qty_ordered' => 3, 'price' => 10.0, 'weight' => 100.0]
    );

    expect($items[0]->getWeight())->toBe(300)
        ->and($items[0]->getItemValue()['amount'])->toBe(3000);
});

it('declares at least one piece for a fractional quantity, which the API would refuse as zero', function () {
    $items = ppsCustomsItems(['country_of_manufacture' => 'CN'], [], ['qty_ordered' => 0.5, 'price' => 10.0]);

    expect($items[0]->getAmount())->toBe(1);
});

it('declares a configurable product once and a virtual product not at all, as a label does', function () {
    $parent  = createOrderItem(['item_id' => 1, 'product_type' => 'configurable', 'name' => 'Shirt'], ['id' => 7]);
    $child   = createOrderItem(['item_id' => 2, 'parent_item' => $parent, 'is_dummy' => true, 'name' => 'Shirt, red'], ['id' => 7]);
    $virtual = createOrderItem(['item_id' => 3, 'is_virtual' => true, 'name' => 'Gift wrap'], ['id' => 7]);
    $items   = customsItemsOf([$parent, $child, $virtual]);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getDescription())->toBe('Shirt');
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
