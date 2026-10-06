<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\InsuranceAmountSetting;
use MyParcelNL\Magento\Model\Settings\Proposition;

/** Belgium is a zone of its own only for an account at home in NL; every other account has three. */

it('gives a Dutch account four zones and every other one three', function (?int $propositionId, array $zones) {
    expect(InsuranceAmountSetting::zonesFor(Proposition::forId($propositionId)))->toBe($zones);
})->with([
    [1, ['local', 'belgium', 'eu', 'row']],
    [3, ['local', 'eu', 'row']],
    [6, ['local', 'eu', 'row']],
    [null, ['local', 'eu', 'row']],
]);

it('reads the cap of the zone a destination falls in', function (?int $propositionId, ?string $destination, string $field) {
    expect(InsuranceAmountSetting::fieldFor($destination, Proposition::forId($propositionId)))->toBe($field);
})->with([
    'dutch account, home'       => [1, 'NL', 'insurance_local_amount'],
    'dutch account, belgium'    => [1, 'BE', 'insurance_belgium_amount'],
    'dutch account, eu'         => [1, 'DE', 'insurance_eu_amount'],
    'dutch account, row'        => [1, 'US', 'insurance_row_amount'],
    'belgian account, home'     => [3, 'BE', 'insurance_local_amount'],
    'belgian account, NL'       => [3, 'NL', 'insurance_eu_amount'],
    'italian account, home'     => [6, 'IT', 'insurance_local_amount'],
    'no proposition, NL'        => [null, 'NL', 'insurance_local_amount'],
    'no proposition, belgium'   => [null, 'BE', 'insurance_eu_amount'],
    'no destination'            => [3, null, 'insurance_local_amount'],
]);
