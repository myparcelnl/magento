<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Fulfilment\OrderLine;

/** Builds a batch of orders with one builder; each order must carry only its own lines. */
function buildBatch(array $itemsPerOrder): array
{
    $builder = createFulfilmentOrderBuilder();
    $built   = [];

    foreach ($itemsPerOrder as $index => $items) {
        $built[] = $builder->build(
            createFulfilmentMagentoOrder(
                ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'],
                ['getItems' => $items, 'getIncrementId' => sprintf('10000000%d', $index + 1)]
            ),
            []
        );
    }

    return $built;
}

it('gives every order in a batch only its own number of lines', function () {
    $built = buildBatch([
        [createOrderItem()],
        [createOrderItem(), createOrderItem()],
        [createOrderItem()],
    ]);

    $counts = array_map(static fn ($order): int => $order->getOrderLines()->count(), $built);

    // Accumulating across the batch would give 1, 3, 4.
    expect($counts)->toBe([1, 2, 1]);
});

it('gives every order in a batch only its own products', function () {
    $built = buildBatch([
        [createOrderItem(['sku' => 'FIRST'])],
        [createOrderItem(['sku' => 'SECOND'])],
    ]);

    $skus = array_map(
        static fn ($order): array => $order->getOrderLines()
            ->map(static fn (OrderLine $line): string => $line->getProduct()->getSku())
            ->toArray(),
        $built
    );

    expect($skus)->toBe([['FIRST'], ['SECOND']]);
});

it('rounds the price and the VAT of an order line to cents', function () {
    [$order] = buildBatch([[createOrderItem(['price' => 0.29, 'tax_amount' => 0.0659])]]);
    $line    = $order->getOrderLines()->first();

    // Truncating gave 28 and 6.
    expect($line->getPrice())->toBe(29)
        ->and($line->getVat())->toBe(7)
        ->and($line->getPriceAfterVat())->toBe(36);
});

it('gives the VAT of one unit, as the price is of one unit', function () {
    // tax_amount is the tax of the whole row; the SDK takes every amount "regardless of quantity".
    [$order] = buildBatch([[createOrderItem(['price' => 10.0, 'tax_amount' => 4.2, 'qty_ordered' => 2])]]);
    $line    = $order->getOrderLines()->first();

    expect($line->getPrice())->toBe(1000)
        ->and($line->getVat())->toBe(210)
        ->and($line->getPriceAfterVat())->toBe(1210)
        ->and($line->getQuantity())->toBe(2);
});

it('reads a price that the database gives as a string', function () {
    [$order] = buildBatch([[createOrderItem(['price' => '19.9900', 'tax_amount' => '4.1979'])]]);
    $line    = $order->getOrderLines()->first();

    expect($line->getPrice())->toBe(1999)
        ->and($line->getVat())->toBe(420);
});

/** The product of the one order line, built with this weight unit. */
function orderLineProduct(array $itemData, string $weightUnit = 'gram')
{
    $order = createFulfilmentOrderBuilder('test-api-key', null, ['print/weight_indication' => $weightUnit])->build(
        createFulfilmentMagentoOrder(['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'], ['getItems' => [createOrderItem($itemData)]]),
        []
    );

    return $order->getOrderLines()->first()->getProduct();
}

it('sends the weight of the order row in grams, whatever the weight unit', function () {
    expect(orderLineProduct(['weight' => 2.5], 'kilo')->getWeight())->toBe(2500)
        ->and(orderLineProduct(['weight' => 0.3], 'kilo')->getWeight())->toBe(300)
        ->and(orderLineProduct(['weight' => 250.0])->getWeight())->toBe(250);
});

it('sends no weight for a row without one, rather than a default', function () {
    expect(orderLineProduct([])->getWeight())->toBeNull();
});

it('sends the sku of the order row, not of the catalog product', function () {
    // A configurable's row holds the sku that was sold; its catalog product is the parent.
    expect(orderLineProduct(['sku' => 'SHIRT-RED'])->getSku())->toBe('SHIRT-RED');
});

it('builds the line of a product that was deleted from the catalog', function () {
    // getProduct() is null then; the row keeps the sku. The SDK sends an unknown size as null.
    [$order] = buildBatch([[createOrderItem(['product' => null, 'sku' => 'OLD-MUG'])]]);
    $product = $order->getOrderLines()->first()->getProduct();

    expect($product->getSku())->toBe('OLD-MUG')
        ->and($product->getWeight())->toBeNull()
        ->and($product->getHeight())->toBeNull();
});
