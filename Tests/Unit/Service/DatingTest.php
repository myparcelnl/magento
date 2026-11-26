<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Dating;

it('answers null for a date it cannot read, rather than throwing', function () {
    expect(Dating::convertDeliveryDate('not a date'))->toBeNull();
});

it('moves a past delivery date to tomorrow', function () {
    expect(Dating::convertDeliveryDate('2020-01-01', 'Y-m-d'))->toBe(date('Y-m-d', strtotime('now +1 day')));
});
