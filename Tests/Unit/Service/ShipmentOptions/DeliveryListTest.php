<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryList;

/** DeliveryList::build() for PostNL with nothing stored, unless given. */
function listOf(array $dated, array $allowed, ?DeliveryOptions $stored = null, string $carrier = 'postnl', bool $datesFailed = false): array
{
    return DeliveryList::build($dated, $allowed, $stored ?? storedDeliveryOptions(null), $carrier, $datesFailed);
}

function storedMorning(string $date): DeliveryOptions
{
    return storedDeliveryOptions(['carrier' => 'postnl', 'deliveryType' => 'morning', 'date' => $date . ' 00:00:00']);
}

it('puts standard and express in the No date group', function () {
    $list = listOf([datedDelivery('2026-10-29', 'morning', '08:00', '12:00')], ['standard', 'express', 'morning']);

    expect($list['groups'][0]['date'])->toBeNull()
        ->and(array_column($list['groups'][0]['entries'], 'value'))->toBe(['standard|', 'express|']);
});

it('groups the dated possibilities per date with their time frame', function () {
    $list = listOf(
        [
            datedDelivery('2026-10-29', 'morning', '08:00', '12:00'),
            datedDelivery('2026-10-29', 'standard'),
            datedDelivery('2026-10-30', 'evening', '18:00', '22:00'),
        ],
        ['standard', 'morning', 'evening']
    );

    expect($list['groups'][1]['date'])->toBe('2026-10-29')
        ->and(array_column($list['groups'][1]['entries'], 'value'))->toBe(['morning|2026-10-29', 'standard|2026-10-29'])
        ->and($list['groups'][1]['entries'][0]['label'])->toBe('Morning 08:00–12:00')
        ->and($list['groups'][2]['date'])->toBe('2026-10-30')
        ->and($list['groups'][2]['entries'][0]['label'])->toBe('Evening 18:00–22:00');
});

it('leaves out a dated entry whose delivery type capabilities do not allow', function () {
    $list = listOf([datedDelivery('2026-10-29', 'morning', '08:00', '12:00'), datedDelivery('2026-10-29', 'standard')], ['standard']);

    expect(deliveryValues($list))->not->toContain('morning|2026-10-29')
        ->toContain('standard|2026-10-29');
});

it('leaves out same-day and early-morning possibilities', function () {
    $list = listOf([datedDelivery('2026-10-29', 'same_day'), datedDelivery('2026-10-29', 'early_morning')], ['standard', 'same_day', 'early_morning']);

    expect(deliveryValues($list))->toBe(['standard|']);
});

it('offers pickup only when capabilities allow it', function () {
    expect(listOf([], ['standard', 'pickup'])['pickup'])->toBeTrue()
        ->and(listOf([], ['standard'])['pickup'])->toBeFalse();
});

it('preselects the stored choice when it is in the list', function () {
    $list = listOf([datedDelivery('2026-10-29', 'morning', '08:00', '12:00')], ['standard', 'morning'], storedMorning('2026-10-29'));

    expect(selectedDelivery($list))->toBe('morning|2026-10-29');
});

it('preselects Standard with no date for a passed or no longer offered date', function () {
    $list = listOf([datedDelivery('2026-10-29', 'morning', '08:00', '12:00')], ['standard', 'morning'], storedMorning('2026-10-20'));

    expect(selectedDelivery($list))->toBe('standard|')
        ->and(deliveryValues($list))->not->toContain('morning|2026-10-20');
});

it('keeps a stored same-day, early-morning or unknown delivery type as a preselected extra entry', function (string $type, ?string $date, string $value) {
    $stored = storedDeliveryOptions(['carrier' => 'postnl', 'deliveryType' => $type, 'date' => null === $date ? null : $date . ' 00:00:00']);
    $list   = listOf([], ['standard'], $stored);

    expect(deliveryValues($list))->toContain($value)
        ->and(selectedDelivery($list))->toBe($value);
})->with([
    'same day'      => ['same_day', '2026-10-30', 'same_day|2026-10-30'],
    'early morning' => ['early_morning', '2026-10-30', 'early_morning|2026-10-30'],
    'unknown'       => ['teleport', null, 'teleport|'],
]);

it('keeps the stored pickup as an extra entry for the same carrier only', function () {
    $stored = storedDeliveryOptions(['carrier' => 'postnl', 'deliveryType' => 'pickup']);

    $sameCarrier  = listOf([], ['standard'], $stored);
    $otherCarrier = listOf([], ['standard'], $stored, 'dhlforyou');

    expect($sameCarrier['pickup'])->toBeTrue()
        ->and(selectedDelivery($sameCarrier))->toBe('pickup|')
        ->and($otherCarrier['pickup'])->toBeFalse()
        ->and(selectedDelivery($otherCarrier))->toBe('standard|');
});

it('keeps the stored choice and warns when the dates failed', function () {
    $list = listOf([], ['standard', 'morning'], storedMorning('2026-10-29'), 'postnl', true);

    expect(deliveryValues($list))->toBe(['standard|', 'morning|2026-10-29'])
        ->and(selectedDelivery($list))->toBe('morning|2026-10-29')
        ->and($list['warning'])->toBeString();
});

it('has no warning when the dates were read', function () {
    expect(listOf([], ['standard'])['warning'])->toBeNull();
});

it('gives the delivery type of the selected entry', function () {
    $list = listOf([datedDelivery('2026-10-29', 'morning', '08:00', '12:00')], ['standard', 'morning'], storedMorning('2026-10-29'));

    expect(DeliveryList::selectedType($list))->toBe('morning')
        ->and(DeliveryList::selectedType(['groups' => []]))->toBeNull();
});
