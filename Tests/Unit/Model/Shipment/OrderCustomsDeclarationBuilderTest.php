<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Model\Shipment\OrderCustomsDeclarationBuilder;
use MyParcelNL\Magento\Service\Weight;

/** The Order v1 customs path: each line at product weight and at the price paid in the order's currency. */
function orderV1CustomsItems(
    array  $productData,
    array  $config = [],
    array  $itemData = [],
    string $homeCountry = 'NL',
    array  $attributes = []
): array
{
    $item  = createOrderItem($itemData, ['id' => 7] + $productData);
    $order = createOrder([
        'getItems'         => [$item],
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig(['print/weight_indication' => 'gram'] + $config);
    $objectManager = customsObjectManager(
        ['classification' => [7 => '6109.10']] + $attributes,
        [7 => $productData['country_of_manufacture'] ?? null],
        $config,
        $homeCountry
    );

    return (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;
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
        ['country_of_manufacture' => 'CN', 'weight' => 100.0, 'price' => 99.0],
        [],
        ['qty_ordered' => 3, 'price' => 10.0]
    );

    expect($items[0]->getWeight())->toBe(300)
        ->and($items[0]->getItemValue()['amount'])->toBe(3000);
});

it('declares at least one piece for a fractional quantity, which the API would refuse as zero', function () {
    $items = orderV1CustomsItems(['country_of_manufacture' => 'CN'], [], ['qty_ordered' => 0.5, 'price' => 10.0]);

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
        ['item_id' => 315, 'product_id' => 9, 'product_type' => 'simple', 'parent_item' => $parent] + $childQty,
        ['id' => 9, 'name' => 'Caterham Red', 'weight' => 100.0, 'price' => 10.0]
    );
    $order  = createOrder([
        'getItems'         => [$parent, $child],
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    $config        = createConfig(['print/weight_indication' => 'gram']);
    $objectManager = customsObjectManager($attributes, $countries, $config);

    return (new OrderCustomsDeclarationBuilder($objectManager, $config, new Weight($config)))->build($order)->items;
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

it('sends the external product reference of a product that has one', function () {
    $items = orderV1CustomsItems([], [], [], 'NL', ['external_product_reference' => [7 => 'zonos-item-1']]);

    expect($items[0]->getExternalProductReference())->toBe('zonos-item-1');
});

it('refuses an external product reference above 64 characters and names the product', function () {
    orderV1CustomsItems(['name' => 'Widget', 'sku' => 'SKU-7'], [], [], 'NL', ['external_product_reference' => [7 => str_repeat('z', 65)]]);
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
