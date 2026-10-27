<?php

declare(strict_types=1);

/** Today and tomorrow are the store's, not UTC's; an empty, unreadable or past date sends no delivery date. */
it('gives the day after the calendar day of the order store', function () {
    expect(datingOn('2026-10-24', 7)->tomorrow(createOrder(['getStoreId' => 7])))->toBe('2026-10-25');
});

it('sends no date for an empty or unreadable one', function (?string $date) {
    expect(datingOn('2026-10-24')->convertDeliveryDate($date, createOrder()))->toBeNull();
})->with([
    'null'       => [null],
    'empty'      => [''],
    'unreadable' => ['not a date'],
]);

it('keeps a date from tomorrow on, at the start of that day', function (string $date, string $expected) {
    expect(datingOn('2026-10-24')->convertDeliveryDate($date, createOrder()))->toBe($expected);
})->with([
    'tomorrow' => ['2026-10-25', '2026-10-25 00:00:00'],
    'later'    => ['2026-10-29 14:00:00', '2026-10-29 00:00:00'],
]);

it('sends no date for a date before tomorrow', function (string $date) {
    expect(datingOn('2026-10-24')->convertDeliveryDate($date, createOrder()))->toBeNull();
})->with([
    'today'  => ['2026-10-24'],
    'passed' => ['2020-01-01'],
]);
