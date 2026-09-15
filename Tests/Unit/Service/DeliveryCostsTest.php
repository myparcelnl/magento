<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\DeliveryCosts;

/**
 * Only the euro-to-cent conversion. It has two callers, both customs declarations, and an amount a
 * cent low on those is an under-declared value to customs.
 */
it('rounds a price whose binary representation falls just short', function () {
    // 0.29 * 100 is 28.999999999999996, so a cast answered 28.
    expect(DeliveryCosts::getPriceInCents(0.29))->toBe(29);
});

it('leaves a price that converts exactly alone', function () {
    expect(DeliveryCosts::getPriceInCents(12.34))->toBe(1234)
        ->and(DeliveryCosts::getPriceInCents(0.07))->toBe(7)
        ->and(DeliveryCosts::getPriceInCents(0.0))->toBe(0);
});

it('answers the same on every PHP version at a half-cent boundary', function () {
    // 1.005 * 100 is 100.49999999999999, below the boundary, so half-up answers 100. PHP 8.4
    // answers that; 8.1 to 8.3 answered 101, because round() pre-rounded to the boundary first.
    expect(DeliveryCosts::getPriceInCents(1.005))->toBe(100)
        ->and(DeliveryCosts::getPriceInCents(1.004))->toBe(100);
});

it('rounds a value that lands exactly on the boundary up', function () {
    // These convert exactly: 0.025 * 100 is 2.5 and not a hair under, unlike 1.005.
    expect(DeliveryCosts::getPriceInCents(0.025))->toBe(3)
        ->and(DeliveryCosts::getPriceInCents(0.015))->toBe(2)
        ->and(DeliveryCosts::getPriceInCents(0.125))->toBe(13);
});
