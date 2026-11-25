<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\ExportReport;

/**
 * An order exports one shipment per collo under the same increment id, and its colli can land in two
 * chunks. One collo shipping and another failing are then both true, and neither may hide the other.
 */

it('names an order whose other collo timed out after one collo shipped', function () {
    $report = new ExportReport();
    $report->succeed('100000001', 9001);
    $report->failCollateral('100000001', 'Operation timed out', false);

    expect($report->collateral())->toBe([])
        ->and($report->failureReasons())->toHaveKey('100000001')
        ->and(implode('; ', $report->failureReasons()['100000001']))
        ->toContain('It is unknown whether MyParcel created every collo of this order: Operation timed out.');
});

it('names an order whose other collo shipped after one collo timed out', function () {
    $report = new ExportReport();
    $report->failCollateral('100000001', 'Operation timed out', false);
    $report->succeed('100000001', 9002);

    expect($report->collateral())->toBe([])
        ->and($report->failureReasons())->toHaveKey('100000001')
        ->and(implode('; ', $report->failureReasons()['100000001']))
        ->toContain('It is unknown whether MyParcel created every collo of this order: Operation timed out.');
});

it('names an order whose other collo was refused after one collo shipped', function () {
    $report = new ExportReport();
    $report->succeed('100000001', 9001);
    $report->failCollateral('100000001', 'The postal code is invalid', true);

    expect($report->collateral())->toBe([])
        ->and($report->failureReasons())->toHaveKey('100000001')
        ->and(implode('; ', $report->failureReasons()['100000001']))
        ->toContain('Not every collo of this order was exported: The postal code is invalid');
});

it('keeps the unknown outcome of a timed-out collo when the order is blamed later', function () {
    $report = new ExportReport();
    $report->failCollateral('100000001', 'Operation timed out', false);
    $report->fail('100000001', 'The MyParcel API did not return a shipment for this order');

    expect(implode('; ', $report->failureReasons()['100000001']))
        ->toContain('It is unknown whether MyParcel created every collo of this order: Operation timed out.');
});

it('keeps the unknown outcome of a timed-out collo of an order that is already blamed', function () {
    $report = new ExportReport();
    $report->fail('100000001', 'Invalid postal code');
    $report->failCollateral('100000001', 'Operation timed out', false);

    expect(implode('; ', $report->failureReasons()['100000001']))
        ->toContain('Invalid postal code')
        ->toContain('It is unknown whether MyParcel created every collo of this order: Operation timed out.');
});

it('says the unknown outcome once when a collo shipped and the order is blamed later', function () {
    $report = new ExportReport();
    $report->failCollateral('100000001', 'Operation timed out', false);
    $report->succeed('100000001', 9002);
    $report->fail('100000001', 'Invalid postal code');

    expect($report->failureReasons()['100000001'])->toHaveCount(2);
});

it('does not ask for a re-run when a blamed order sits next to orders that timed out', function () {
    $report = new ExportReport();
    $report->fail('100000001', 'Invalid postal code');
    $report->failCollateral('100000002', 'Operation timed out', false);

    expect($report->failureMessages()[1])
        ->toStartWith('Orders that MyParcel did not confirm: 1. It is unknown whether they shipped.');
});

it('still lists an order the API refused without any text', function () {
    $report = new ExportReport();
    $report->fail('100000001', '   ');

    expect(array_map('strval', array_keys($report->failureReasons())))->toBe(['100000001'])
        ->and($report->failureReasons()['100000001'][0])->not->toBe('');
});

it('gives the reason for refused orders next to a blamed one, and does not ask to correct the blamed one', function () {
    // Two chunks: one named order 5, the other was refused for the whole account.
    $report = new ExportReport();
    $report->fail('100000005', 'Invalid postal code');
    $report->failCollateral('100000006', 'your account is not active', true);

    expect($report->failureMessages()[1])
        ->toBe('Orders that were not exported because MyParcel refused this batch: 1. Reason: your account is not active');
});

it('words the collateral line by the orders still in it', function () {
    $report = new ExportReport();
    // A timed-out collo of A, then A's other collo ships: A gets a line of its own.
    $report->failCollateral('100000001', 'Operation timed out', false);
    $report->succeed('100000001', 9001);
    // D's chunk is refused as a whole, so D was certainly not created.
    $report->failCollateral('100000004', 'batch rejected', true);

    $messages = $report->failureMessages();

    expect(end($messages))->toStartWith('Orders that were not exported because MyParcel refused this batch: 1.')
        ->and($report->collateral())->toBe(['100000004']);
});
