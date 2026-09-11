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

it('rounds a third-of-a-cent price to the nearest cent', function () {
    expect(DeliveryCosts::getPriceInCents(1.005))->toBe(101)
        ->and(DeliveryCosts::getPriceInCents(1.004))->toBe(100);
});
