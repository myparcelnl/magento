<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\CustomsDeclarationBuilder;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesMoney;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * The v11 customs stack is a second builder, unrelated to the Order v1 one the
 * fulfilment path uses. ConsignmentEncode::DEFAULT_CURRENCY named the
 * currency before; it is one of the classes beta.22 removed, so the same value
 * is now read off the generated money model.
 *
 * Product data (HS code, country of manufacture) is fetched in two batch
 * queries keyed by product id, never per item — that is what the mocks model.
 */
function createCustomsDeclarationBuilder(string $classification = '61', int $productId = 99): CustomsDeclarationBuilder
{
    $config = createConfig(['print/weight_indication' => 'gram']);

    return new CustomsDeclarationBuilder(
        customsObjectManager([$productId => $classification], [$productId => 'CN'], $config),
        $config,
        new Weight($config)
    );
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

it('declares a multi-quantity line at line level, not per unit', function () {
    // A customs item carries the line's weight and the line's value while amount carries the count,
    // so the module multiplies both itself. Every other fixture here ships a qty of 1, which cannot
    // tell the two readings apart. 3702 also pins that cents are multiplied, not euros: converting
    // 12.34 * 3 in one go truncates to 3701.
    $item = createShipmentItem([
        'name'       => 'Widget',
        'qty'        => 3,
        'weight'     => 250.0,
        'price'      => 12.34,
        'product_id' => 99,
    ]);

    [, $items] = buildCustomsFor([$item]);

    expect($items[0]->getAmount())->toBe(3);
    expect($items[0]->getWeight())->toBe(750);
    expect(customsItemValue($items[0]))->toBe(['amount' => 3702, 'currency' => RefTypesMoney::CURRENCY_EUR]);
});

it('carries a line at the API maximum of 99999 pieces', function () {
    $item = createShipmentItem([
        'name'       => 'Widget',
        'qty'        => 99999,
        'weight'     => 1.0,
        'price'      => 0.01,
        'product_id' => 99,
    ]);

    [, $items] = buildCustomsFor([$item]);

    expect($items[0]->getAmount())->toBe(99999);
});

it('refuses a line above the API maximum rather than truncating it', function () {
    // Truncating under-declared the pieces, the weight and the value at once, because amount
    // feeds all three. The name is in the message so the admin can find the offending line.
    $item = createShipmentItem([
        'name'       => 'Widget',
        'qty'        => 100000,
        'weight'     => 1.0,
        'price'      => 0.01,
        'product_id' => 99,
    ]);

    expect(fn() => buildCustomsFor([$item]))
        ->toThrow(RuntimeException::class, 'Customs item "Widget" has 100000 pieces; the maximum per item is 99999');
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
    // MyParcelCustomsItem truncated at 50 with Str::limit; the generated setter throws instead.
    // The ellipsis is inside the 50, not added to it.
    $item = createShipmentItem([
        'name'       => str_repeat('a', 80),
        'qty'        => 1,
        'weight'     => 250.0,
        'price'      => 1.0,
        'product_id' => 99,
    ]);

    [, $items] = buildCustomsFor([$item]);

    expect($items[0]->getDescription())->toBe(str_repeat('a', 47) . '...');
});

/** The stored HS code as the shipment carries it. See "HS code length" in docs/sdk-v11.md. */
function classificationOf(string $stored): string
{
    $item = createShipmentItem(['name' => 'Widget', 'qty' => 1, 'weight' => 250.0, 'price' => 1.0, 'product_id' => 99]);

    [, $items] = buildCustomsFor([$item], $stored);

    return $items[0]->getClassification();
}

it('keeps the leading zero of an HS code', function () {
    expect(classificationOf('0901'))->toBe('0901');
});

it('sends a dotted HS code as digits', function () {
    expect(classificationOf('6109.10'))->toBe('610910');
});

it('cuts a national tariff code to its ten digit parent', function () {
    expect(classificationOf('6109.10.00.10.123'))->toBe('6109100010');
});
