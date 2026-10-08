<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Dating;

/** An empty or unreadable date sends no delivery date; a date that has passed becomes tomorrow. */
it('sends no date for an empty or unreadable one', function (?string $date) {
    expect(Dating::convertDeliveryDate($date))->toBeNull();
})->with([
    'null'       => [null],
    'empty'      => [''],
    'unreadable' => ['not a date'],
]);

it('keeps a future date, at the start of that day', function () {
    $future = date('Y-m-d', strtotime('+5 days'));

    expect(Dating::convertDeliveryDate($future . ' 14:00:00'))->toBe($future . ' 00:00:00');
});

it('moves a date that has passed to tomorrow', function () {
    expect(Dating::convertDeliveryDate('2020-01-01', 'Y-m-d'))->toBe(date('Y-m-d', strtotime('+1 day')));
});
