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
    // The real contract-definitions shape: the acceptance options without requires and excludes,
    // which only the capabilities answer carries.
    $options = array_map(static function (array $option): array {
        return array_diff_key($option, ['requires' => true, 'excludes' => true]);
    }, acceptanceOptionsFor('POSTNL'));

    $rows = $withContract
        ? [settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem(['options' => $options])])]
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

// Contract definitions carry no excludes (spec, SDK v11.3.0), so the validator refuses nothing until
// the API and the SDK add them. These cases refused a save while the old parser read fake excludes.
it('lets the save through, because contract definitions carry no excludes', function (array $posted, array $stored) {
    $validator = excludedOptionValidator($posted, $stored);

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))->toBeNull()
        ->and(excludedRejection($validator, EXCLUDED_RECEIPT_CODE_PATH))->toBeNull();
})->with([
    'the other is already forced on' => [[EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1']], [EXCLUDED_RECEIPT_CODE_PATH => '1']],
    'the same save forces both on'   => [[EXCLUDED_AGE_CHECK_PATH => ['value' => '1'], EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1']], []],
    'the other is inherited'         => [[EXCLUDED_RECEIPT_CODE_PATH => ['inherit' => '1']], [EXCLUDED_RECEIPT_CODE_PATH => '1']],
]);

it('accepts the option when the same save switches the other off', function () {
    $validator = excludedOptionValidator(
        [EXCLUDED_RECEIPT_CODE_PATH => ['value' => '0']],
        [EXCLUDED_RECEIPT_CODE_PATH => '1']
    );

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))->toBeNull();
});

it('accepts an option that is not forced on', function () {
    $validator = excludedOptionValidator([EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1']], [EXCLUDED_RECEIPT_CODE_PATH => '1']);

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH, '0'))->toBeNull();
});

it('accepts anything when the contract cannot be read', function () {
    $validator = excludedOptionValidator([EXCLUDED_RECEIPT_CODE_PATH => ['value' => '1']], [EXCLUDED_RECEIPT_CODE_PATH => '1'], false);

    expect(excludedRejection($validator, EXCLUDED_AGE_CHECK_PATH))->toBeNull();
});
