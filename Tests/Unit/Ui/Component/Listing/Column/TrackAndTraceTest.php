<?php

declare(strict_types=1);

use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

// Magento\Ui is a bootstrap stub here, so the column cannot run prepareDataSource() in a unit test.
function cellWithoutLinksFor(?string $value): ?string
{
    return invokePrivateMethod(newInstanceWithoutConstructor(TrackAndTrace::class), 'cellWithoutLinks', [$value]);
}

it('shows nothing for a barcode list that gave no link', function () {
    expect(cellWithoutLinksFor('["3SMYPA111","3SMYPA222"]'))->toBeNull()
        ->and(cellWithoutLinksFor('["–"]'))->toBeNull()
        ->and(cellWithoutLinksFor(null))->toBeNull();
});

it('escapes any other stored value, as the grid binds the cell as html', function () {
    expect(cellWithoutLinksFor('3SMYPA111'))->toBe('3SMYPA111')
        ->and(cellWithoutLinksFor('<b>'))->toBe('&lt;b&gt;');
});
