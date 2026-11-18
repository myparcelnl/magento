<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\LabelPdfMerger;

/**
 * A mixed batch is one download, and the labels of one account must stay together and in the order
 * the accounts were asked. Fixtures live in Tests/Helpers/PdfFixtures.php.
 */

it('keeps every page of every account', function () {
    $merged = (new LabelPdfMerger())->merge([makePdf(2, [100, 200]), makePdf(3, [150, 150])])['pdf'];

    expect(pageSizes($merged))->toHaveCount(5);
});

it('keeps each account its own pages together, in the order they were handed in', function () {
    $merged = (new LabelPdfMerger())->merge([makePdf(2, [100, 200]), makePdf(3, [150, 150])])['pdf'];

    expect(pageSizes($merged))->toBe([
        ['width' => 100, 'height' => 200],
        ['width' => 100, 'height' => 200],
        ['width' => 150, 'height' => 150],
        ['width' => 150, 'height' => 150],
        ['width' => 150, 'height' => 150],
    ]);
});

it('hands a single account its own document back untouched', function () {
    $pdf = makePdf(2, [100, 200]);

    // Byte for byte: re-writing one account's labels through FPDI would risk what the API sent for
    // no gain at all.
    expect((new LabelPdfMerger())->merge([$pdf]))->toBe(['pdf' => $pdf, 'skipped' => 0]);
});

it('skips the accounts that returned nothing', function () {
    $pdf = makePdf(2, [100, 200]);

    expect((new LabelPdfMerger())->merge(['', $pdf, ''])['pdf'])->toBe($pdf);
});

it('gives nothing back when no account returned a document', function () {
    expect((new LabelPdfMerger())->merge([])['pdf'])->toBe('')
        ->and((new LabelPdfMerger())->merge(['', ''])['pdf'])->toBe('');
});

it('skips a document it cannot parse and still merges the others', function () {
    mockLoggerFacade()->shouldReceive('warning')->once();

    $merged = (new LabelPdfMerger())->merge([makePdf(2, [100, 200]), 'not a pdf', makePdf(1, [150, 150])]);

    expect($merged['skipped'])->toBe(1)
        ->and(pageSizes($merged['pdf']))->toHaveCount(3);
});

it('gives nothing back when no document can be parsed', function () {
    mockLoggerFacade()->shouldReceive('warning')->twice();

    expect((new LabelPdfMerger())->merge(['not a pdf', 'nor this']))->toBe(['pdf' => '', 'skipped' => 2]);
});
