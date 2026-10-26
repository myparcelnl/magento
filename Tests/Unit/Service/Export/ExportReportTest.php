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
