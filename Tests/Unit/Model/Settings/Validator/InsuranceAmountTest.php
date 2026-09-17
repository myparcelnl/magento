<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Validator\InsuranceAmount;

const VALIDATOR_INSURANCE_PATH = 'myparcelnl_magento_postnl_settings/default_options/insurance_local_amount';

/** @param array<string, string> $rowsByPath */
function insuranceValidator(array $rowsByPath = []): InsuranceAmount
{
    return new InsuranceAmount(contractDefinitionsFor($rowsByPath, [
        'default' => [0 => [MyParcelNL\Magento\Service\Config::XML_PATH_API_KEY => 'live-key']],
    ]));
}

function validateAmount($value, array $rowsByPath = []): ?string
{
    $rejection = insuranceValidator($rowsByPath)->validate(VALIDATOR_INSURANCE_PATH, $value, 'default', 0);

    return null === $rejection ? null : (string) $rejection;
}

it('claims the four insurance amount paths and nothing else', function () {
    $validator = insuranceValidator();

    expect($validator->handles(VALIDATOR_INSURANCE_PATH))->toBeTrue()
        ->and($validator->handles('myparcelnl_magento_gls_settings/default_options/insurance_row_amount'))->toBeTrue()
        ->and($validator->handles('myparcelnl_magento_postnl_settings/default_options/insurance_percentage'))->toBeFalse()
        ->and($validator->handles('myparcelnl_magento_postnl_settings/default_options/insurance_from_price'))->toBeFalse()
        ->and($validator->handles('myparcelnl_magento_general/api/key'))->toBeFalse();
});

it('does not claim an insurance field for a carrier the module has no settings path for', function () {
    expect(insuranceValidator()->handles('myparcelnl_magento_nonsense_settings/default_options/insurance_local_amount'))
        ->toBeFalse();
});

it('accepts an amount inside the contract range', function () {
    expect(validateAmount('2500', contractRow()))->toBeNull();
});

it('refuses an amount above the contract maximum, naming the range', function () {
    expect(validateAmount('9000', contractRow(250000)))
        ->toContain('9000')
        ->toContain('2500');
});

it('refuses an amount between zero and the contract minimum', function () {
    expect(validateAmount('50', contractRow(250000, 10000)))->not->toBeNull();
});

it('accepts zero when insurance is optional, whatever the minimum is', function () {
    expect(validateAmount('0', contractRow(250000, 10000)))->toBeNull();
});

it('refuses zero when the contract requires insurance', function () {
    expect(validateAmount('0', contractRow(250000, 10000, true)))->not->toBeNull();
});

it('judges a cleared field as zero rather than waving it through', function () {
    expect(validateAmount('', contractRow(250000, 10000)))->toBeNull()
        ->and(validateAmount('', contractRow(250000, 10000, true)))->not->toBeNull();
});

it('refuses a value that is not a number at all', function () {
    // Without this it would save, and every reader coerces it to 0 — a typo that silently switches
    // insurance off.
    expect(validateAmount('abc', contractRow()))->toContain('whole number');
});

it('refuses a fractional amount rather than truncating it', function () {
    expect(validateAmount('12.5', contractRow()))->toContain('whole number');
});

it('refuses a negative amount', function () {
    expect(validateAmount('-100', contractRow()))->toContain('whole number');
});

it('judges a value that is not a scalar as zero, like a cleared field', function () {
    expect(validateAmount(null, contractRow()))->toBeNull()
        ->and(validateAmount(null, contractRow(250000, 10000, true)))->not->toBeNull();
});

it('accepts any amount when no bound could be resolved', function () {
    expect(validateAmount('9000'))->toBeNull();
});

it('still refuses a malformed value when no bound could be resolved', function () {
    expect(validateAmount('abc'))->toContain('whole number');
});
