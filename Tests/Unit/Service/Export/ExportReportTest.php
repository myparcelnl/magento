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

it('does not ask for a re-run when a blamed order sits next to orders that timed out', function () {
    $report = new ExportReport();
    $report->fail('100000001', 'Invalid postal code');
    $report->failCollateral('100000002', 'Operation timed out', false);

    expect($report->failureMessages()[1])
        ->toStartWith('Orders for which MyParcel could not be reached: 1. It is unknown whether they shipped.');
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
