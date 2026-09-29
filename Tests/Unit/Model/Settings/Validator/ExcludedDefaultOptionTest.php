<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use MyParcelNL\Magento\Model\Settings\Validator\ExcludedDefaultOption;
use MyParcelNL\Magento\Service\Config;

const EXCLUDED_AGE_CHECK_PATH    = 'myparcelnl_magento_postnl_settings/default_options/age_check_active';
const EXCLUDED_RECEIPT_CODE_PATH = 'myparcelnl_magento_postnl_settings/default_options/receipt_code_active';

/**
 * @param array<string,array> $posted the form's `config` param, by path
 * @param array<string,string> $stored what core_config_data holds before this save, by path
 */
function excludedOptionValidator(array $posted = [], array $stored = [], bool $withContract = true): ExcludedDefaultOption
{
    $rows = $withContract
        ? [settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem(['options' => acceptanceOptionsFor('POSTNL')])])]
        : [];

    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->with('config', [])->andReturn($posted);

    return new ExcludedDefaultOption(
        contractDefinitionsFor($rows, ['default' => [0 => [Config::XML_PATH_API_KEY => 'live-key']]]),
        $request,
        mockScopeConfig($stored)
    );
}

function excludedRejection(ExcludedDefaultOption $validator, string $path, string $value = '1'): ?string
{
    $rejection = $validator->validate($path, $value, 'default', 0);

    return null === $rejection ? null : (string) $rejection;
}

it('claims the forced-option fields and nothing else', function () {
    $validator = excludedOptionValidator();

    expect($validator->handles(EXCLUDED_AGE_CHECK_PATH))->toBeTrue()
        ->and($validator->handles('myparcelnl_magento_hoopla_settings/default_options/no_tracking_active'))->toBeTrue()
        ->and($validator->handles('myparcelnl_magento_postnl_settings/default_options/age_check_from_price'))->toBeFalse()
        ->and($validator->handles('myparcelnl_magento_postnl_settings/delivery/signature_active'))->toBeFalse()
        ->and($validator->handles('myparcelnl_magento_general/api/key'))->toBeFalse();
});

it('refuses to force an option on next to one it excludes that is already forced on', function () {
    $validator = excludedOptionValidator(
        [EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1']],
        [EXCLUDED_RECEIPT_CODE_PATH => '1']
    );

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))
        ->toBe('Automate "age check" was not saved: it cannot ship together with "receipt code", which is already automated.')
        ->and(excludedRejection($validator, EXCLUDED_RECEIPT_CODE_PATH))->toBeNull();
});

it('refuses both when the same save forces two excluding options on', function () {
    $validator = excludedOptionValidator([
        EXCLUDED_AGE_CHECK_PATH    => ['value' => '1'],
        EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1'],
    ]);

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))->not->toBeNull()
        ->and(excludedRejection($validator, EXCLUDED_RECEIPT_CODE_PATH))->not->toBeNull();
});

it('accepts the option when the same save switches the other off', function () {
    $validator = excludedOptionValidator(
        [EXCLUDED_RECEIPT_CODE_PATH => ['value' => '0']],
        [EXCLUDED_RECEIPT_CODE_PATH => '1']
    );

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))->toBeNull();
});

it('reads an inherited value from the parent scope', function () {
    $validator = excludedOptionValidator(
        [EXCLUDED_RECEIPT_CODE_PATH => ['inherit' => '1']],
        [EXCLUDED_RECEIPT_CODE_PATH => '1']
    );

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))->not->toBeNull();
});

it('accepts an option that is not forced on', function () {
    $validator = excludedOptionValidator([EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1']], [EXCLUDED_RECEIPT_CODE_PATH => '1']);

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH, '0'))->toBeNull();
});

it('accepts anything when the contract cannot be read', function () {
    $validator = excludedOptionValidator([EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1']], [EXCLUDED_RECEIPT_CODE_PATH => '1'], false);

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))->toBeNull();
});
