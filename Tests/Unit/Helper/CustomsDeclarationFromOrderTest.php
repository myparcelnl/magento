<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Helper\CustomsDeclarationFromOrder;

/** The PPS customs path: each line at product weight and at the price paid in the order's currency. */
function ppsCustomsItems(array $productData, array $config = [], array $itemData = []): array
{
    $item  = createOrderItem($itemData, ['id' => 7] + $productData);
    $order = createOrder([
        'getItems'         => [$item],
        'getIncrementId'   => '100000001',
        'getOrderCurrency' => new DataObject(['code' => 'EUR']),
    ]);

    customsObjectManager(
        [7 => '6109.10'],
        [7 => $productData['country_of_manufacture'] ?? null],
        createConfig(['print/weight_indication' => 'gram'] + $config),
        true
    );

    return (new CustomsDeclarationFromOrder($order))->createCustomsDeclaration()->items;
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
        ['country_of_manufacture' => 'CN', 'weight' => 100.0, 'price' => 99.0],
        [],
        ['qty_ordered' => 3, 'price' => 10.0]
    );

    expect($items[0]->getWeight())->toBe(300)
        ->and($items[0]->getItemValue()['amount'])->toBe(3000);
});
