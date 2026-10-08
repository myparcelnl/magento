<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Fulfilment\OrderLine;

/**
 * The order-lines collection was created once for a whole PPS batch, so the second order carried
 * the first one's lines as well and the third carried both. The customer was invoiced for products
 * they never ordered.
 */
function buildBatch(array $itemsPerOrder): array
{
    $builder = createFulfilmentOrderBuilder();
    $built   = [];

    foreach ($itemsPerOrder as $index => $items) {
        $built[] = $builder->build(
            createFulfilmentMagentoOrder(
                ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'],
                ['getItems' => $items, 'getIncrementId' => sprintf('10000000%d', $index + 1)]
            )
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
        [createOrderItem([], ['sku' => 'FIRST'])],
        [createOrderItem([], ['sku' => 'SECOND'])],
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

it('reads a price that the database gives as a string', function () {
    [$order] = buildBatch([[createOrderItem(['price' => '19.9900', 'tax_amount' => '4.1979'])]]);
    $line    = $order->getOrderLines()->first();

    expect($line->getPrice())->toBe(1999)
        ->and($line->getVat())->toBe(420);
});
