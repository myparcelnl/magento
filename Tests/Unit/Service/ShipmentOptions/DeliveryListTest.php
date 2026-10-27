<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryList;

// datedDelivery() lives in Tests/Helpers/DeliveryChoiceFixtures.php, storedDeliveryOptions() in
// Tests/Helpers/ShipmentOptionsResolverFixtures.php.

/** @return string[] every entry value of the list, in order */
function deliveryValues(array $list): array
{
    $values = [];

    foreach ($list['groups'] as $group) {
        foreach ($group['entries'] as $entry) {
            $values[] = $entry['value'];
        }
    }

    return $values;
}

function selectedDelivery(array $list): ?string
{
    foreach ($list['groups'] as $group) {
        foreach ($group['entries'] as $entry) {
            if ($entry['selected']) {
                return $entry['value'];
            }
        }
    }

    return null;
}

function storedMorning(string $date, string $carrier = 'postnl'): \MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions
{
    return storedDeliveryOptions(['carrier' => $carrier, 'deliveryType' => 'morning', 'date' => $date . ' 00:00:00']);
}

it('puts standard and express in the No date group', function () {
    $list = DeliveryList::build(
        [datedDelivery('2026-10-29', 'morning', '08:00', '12:00')],
        ['standard', 'express', 'morning'],
        storedDeliveryOptions(null),
        'postnl',
        false
    );

    expect($list['groups'][0]['date'])->toBeNull()
        ->and(array_column($list['groups'][0]['entries'], 'value'))->toBe(['standard|', 'express|']);
});

it('groups the dated possibilities per date with their time frame', function () {
    $list = DeliveryList::build(
        [
            datedDelivery('2026-10-29', 'morning', '08:00', '12:00'),
            datedDelivery('2026-10-29', 'standard'),
            datedDelivery('2026-10-30', 'evening', '18:00', '22:00'),
        ],
        ['standard', 'morning', 'evening'],
        storedDeliveryOptions(null),
        'postnl',
        false
    );

    expect($list['groups'][1]['date'])->toBe('2026-10-29')
        ->and(array_column($list['groups'][1]['entries'], 'value'))->toBe(['morning|2026-10-29', 'standard|2026-10-29'])
        ->and($list['groups'][1]['entries'][0]['label'])->toBe('Morning 08:00–12:00')
        ->and($list['groups'][2]['date'])->toBe('2026-10-30')
        ->and($list['groups'][2]['entries'][0]['label'])->toBe('Evening 18:00–22:00');
});

it('leaves out a dated entry whose delivery type capabilities do not allow', function () {
    $list = DeliveryList::build(
        [datedDelivery('2026-10-29', 'morning', '08:00', '12:00'), datedDelivery('2026-10-29', 'standard')],
        ['standard'],
        storedDeliveryOptions(null),
        'postnl',
        false
    );

    expect(deliveryValues($list))->not->toContain('morning|2026-10-29')
        ->toContain('standard|2026-10-29');
});

it('leaves out same-day and early-morning possibilities', function () {
    $list = DeliveryList::build(
        [datedDelivery('2026-10-29', 'same_day'), datedDelivery('2026-10-29', 'early_morning')],
        ['standard', 'same_day', 'early_morning'],
        storedDeliveryOptions(null),
        'postnl',
        false
    );

    expect(deliveryValues($list))->toBe(['standard|']);
});

it('offers pickup only when capabilities allow it', function () {
    expect(DeliveryList::build([], ['standard', 'pickup'], storedDeliveryOptions(null), 'postnl', false)['pickup'])->toBeTrue()
        ->and(DeliveryList::build([], ['standard'], storedDeliveryOptions(null), 'postnl', false)['pickup'])->toBeFalse();
});

it('preselects the stored choice when it is in the list', function () {
    $list = DeliveryList::build(
        [datedDelivery('2026-10-29', 'morning', '08:00', '12:00')],
        ['standard', 'morning'],
        storedMorning('2026-10-29'),
        'postnl',
        false
    );

    expect(selectedDelivery($list))->toBe('morning|2026-10-29');
});

it('preselects Standard with no date for a passed or no longer offered date', function () {
    $list = DeliveryList::build(
        [datedDelivery('2026-10-29', 'morning', '08:00', '12:00')],
        ['standard', 'morning'],
        storedMorning('2026-10-20'),
        'postnl',
        false
    );

    expect(selectedDelivery($list))->toBe('standard|')
        ->and(deliveryValues($list))->not->toContain('morning|2026-10-20');
});

it('keeps a stored same-day, early-morning or unknown delivery type as a preselected extra entry', function (string $type, ?string $date, string $value) {
    $stored = storedDeliveryOptions(['carrier' => 'postnl', 'deliveryType' => $type, 'date' => null === $date ? null : $date . ' 00:00:00']);
    $list   = DeliveryList::build([], ['standard'], $stored, 'postnl', false);

    expect(deliveryValues($list))->toContain($value)
        ->and(selectedDelivery($list))->toBe($value);
})->with([
    'same day'      => ['same_day', '2026-10-30', 'same_day|2026-10-30'],
    'early morning' => ['early_morning', '2026-10-30', 'early_morning|2026-10-30'],
    'unknown'       => ['teleport', null, 'teleport|'],
]);

it('keeps the stored pickup as an extra entry for the same carrier only', function () {
    $stored = storedDeliveryOptions(['carrier' => 'postnl', 'deliveryType' => 'pickup']);

    $sameCarrier  = DeliveryList::build([], ['standard'], $stored, 'postnl', false);
    $otherCarrier = DeliveryList::build([], ['standard'], $stored, 'dhlforyou', false);

    expect($sameCarrier['pickup'])->toBeTrue()
        ->and(selectedDelivery($sameCarrier))->toBe('pickup|')
        ->and($otherCarrier['pickup'])->toBeFalse()
        ->and(selectedDelivery($otherCarrier))->toBe('standard|');
});

it('keeps the stored choice and warns when the dates failed', function () {
    $list = DeliveryList::build([], ['standard', 'morning'], storedMorning('2026-10-29'), 'postnl', true);

    expect(deliveryValues($list))->toBe(['standard|', 'morning|2026-10-29'])
        ->and(selectedDelivery($list))->toBe('morning|2026-10-29')
        ->and($list['warning'])->toBeString();
});

it('has no warning when the dates were read', function () {
    expect(DeliveryList::build([], ['standard'], storedDeliveryOptions(null), 'postnl', false)['warning'])->toBeNull();
});
