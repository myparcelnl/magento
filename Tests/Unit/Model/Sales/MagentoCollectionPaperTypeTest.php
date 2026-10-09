<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\LabelPositions;

/**
 * The paper size an export ends up printing on. Only the modal picks one; every other flow — the
 * grid's direct mass action, a row action — sends no mypa_paper_size at all, and used to fall back
 * to a hard-coded A6 whatever the admin had configured.
 */

it('prints a print with no chosen paper size on the configured paper type', function ($paperType, $expected) {
    expect(optionsFromParams([], $paperType)['positions'])->toBe($expected);
})->with([
    'A4 fills a sheet' => ['A4', LabelPositions::A4_SHEET],
    'A6 stays A6'      => ['A6', null],
    'not configured'   => [null, null],
]);

it('lets the modal override the configured paper type', function () {
    // The modal always sends the radio, so an explicit choice is the one case where the setting
    // must not win.
    expect(optionsFromParams(['mypa_paper_size' => 'A6'], 'A4')['positions'])->toBeNull();
});

it('keeps the positions the modal ticked', function () {
    $options = optionsFromParams(
        ['mypa_paper_size' => 'A4', 'mypa_positions' => ['2', '4']],
        'A6'
    );

    expect($options['positions'])->toBe(['2', '4']);
});
