<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\IdList;

/**
 * The invariant four call sites had drifted on. A zero reaching a batch API call asks it about a
 * shipment nobody owns.
 */
it('cleans a list of ids', function (array $given, array $expected) {
    expect(IdList::ints($given))->toBe($expected);
})->with([
    'drops a zero'                  => [[1, 0, 2], [1, 2]],
    'drops a non-numeric value'     => [[1, 'nope', 2], [1, 2]],
    'drops a numeric string zero'   => [['0', 1], [1]],
    'keeps a numeric string id'     => [['7'], [7]],
    'drops a null'                  => [[null, 3], [3]],
    'keeps each id once'            => [[4, 4, 5], [4, 5]],
    'renumbers the keys from zero'  => [[9 => 1, 3 => 2], [1, 2]],
    'answers empty for an empty list' => [[], []],
]);
