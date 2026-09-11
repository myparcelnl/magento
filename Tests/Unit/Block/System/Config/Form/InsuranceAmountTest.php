<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Block\System\Config\Form\InsuranceAmount;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Service\Settings;

/**
 * Magento's block base class is an empty stub under these tests, so the two accessors the block
 * inherits are declared here rather than reflected onto a parent that does not have them.
 */
class InsuranceAmountUnderTest extends InsuranceAmount
{
    public $request;

    public array $field = [];

    public function getRequest()
    {
        return $this->request;
    }

    public function getData($key = '', $index = null)
    {
        return 'field' === $key ? $this->field : null;
    }
}

/**
 * @param array<string, string> $rowsByPath the stored account settings rows
 */
function insuranceAmountBlock(
    string $path,
    array  $rowsByPath,
    array  $scope = [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
    bool   $hasOwnValue = true,
    string $storedValue = ''
): InsuranceAmountUnderTest {
    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturnUsing(
        static fn (string $p) => $rowsByPath[$p] ?? null
    );

    $config = createConfig([], [], [], [
        $scope[0] => [$scope[1] => [
            Config::XML_PATH_API_KEY => 'live-key',
            $path                    => $storedValue,
        ]],
    ]);

    $settings = Mockery::mock(Settings::class);
    $settings->shouldReceive('getCurrentScopeFromRequest')->andReturn($scope);
    $settings->shouldReceive('hasOwnValue')->andReturn($hasOwnValue);

    $block = newInstanceWithoutConstructor(InsuranceAmountUnderTest::class);
    setPrivateProperty($block, 'contractDefinitions', new ContractDefinitions($scopeConfig, new Fingerprint(), $config));
    setPrivateProperty($block, 'settings', $settings);
    setPrivateProperty($block, 'config', $config);
    setPrivateProperty($block, 'resolved', false);
    setPrivateProperty($block, 'range', null);

    $block->request = Mockery::mock(RequestInterface::class);
    $block->field   = ['path' => $path];

    return $block;
}

const POSTNL_LOCAL_AMOUNT_PATH = 'myparcelnl_magento_postnl_settings/default_options/insurance_local_amount';

it('states the contract range on the field', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [
        settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem()]),
    ]);

    expect($block->getRange()->min())->toBe(0)
        ->and($block->getRange()->max())->toBe(5000);
});

it('emits the contract range as the field range', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [
        settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem()]),
    ]);

    expect($block->getValidationClass())
        ->toBe('validate-number validate-zero-or-greater validate-number-range number-range-0-5000');
});

it('keeps zero enterable below an optional contract minimum, leaving the gap to the save', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [
        settingsPathFor('live-key') => accountSettingsRow([
            contractDefinitionItem([
                'options' => capabilityOptions(['insurance' => [
                    'min' => ['amount' => 10000],
                    'max' => ['amount' => 250000],
                ]]),
            ]),
        ]),
    ]);

    expect($block->getValidationClass())
        ->toBe('validate-number validate-zero-or-greater validate-number-range number-range-0-2500')
        ->and($block->getRange()->allows(0))->toBeTrue()
        ->and($block->getRange()->allows(50))->toBeFalse();
});

it('floors the field at the minimum when the contract requires insurance', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [
        settingsPathFor('live-key') => accountSettingsRow([
            contractDefinitionItem([
                'options' => capabilityOptions(['insurance' => [
                    'isRequired' => true,
                    'min'        => ['amount' => 10000],
                    'max'        => ['amount' => 250000],
                ]]),
            ]),
        ]),
    ]);

    expect($block->getValidationClass())
        ->toBe('validate-number validate-zero-or-greater validate-number-range number-range-100-2500')
        ->and($block->getRange()->allows(0))->toBeFalse();
});

it('validates as a plain number when no bound could be resolved', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, []);

    expect($block->getRange())->toBeNull()
        ->and($block->getValidationClass())->toBe('validate-number validate-zero-or-greater');
});

it('resolves the carrier from the path, not from the path segment', function () {
    $upsPath = 'myparcelnl_magento_ups_settings/default_options/insurance_local_amount';

    $block = insuranceAmountBlock($upsPath, [
        settingsPathFor('live-key') => accountSettingsRow([
            contractDefinitionItem([
                'carrier' => 'UPS_STANDARD',
                'options' => capabilityOptions(['insurance' => [
                    'min' => ['amount' => 0],
                    'max' => ['amount' => 100000],
                ]]),
            ]),
        ]),
    ]);

    expect($block->getRange()->max())->toBe(1000);
});

it('has no bound for a path that is not an insurance amount', function () {
    $block = insuranceAmountBlock('myparcelnl_magento_postnl_settings/default_options/insurance_percentage', [
        settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem()]),
    ]);

    expect($block->getRange())->toBeNull();
});

it('names the field the way the settings form posts it', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, []);

    // The template appends [value], exactly as dynamic_settings.phtml does for its own controls.
    expect($block->getFieldName())->toBe('config[' . POSTNL_LOCAL_AMOUNT_PATH . ']')
        ->and($block->getFieldHtmlId())
        ->toBe('myparcelnl_magento_postnl_settings_default_options_insurance_local_amount');
});

it('greys the field out at a scope that inherits its value', function () {
    $inheriting = insuranceAmountBlock(
        POSTNL_LOCAL_AMOUNT_PATH,
        [],
        [ScopeInterface::SCOPE_STORES, 3],
        false
    );

    $own = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [], [ScopeInterface::SCOPE_STORES, 3], true);

    expect($inheriting->isDisabled())->toBeTrue()
        ->and($own->isDisabled())->toBeFalse();
});

it('shows the value stored at the scope being edited', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [], [ScopeInterface::SCOPE_STORES, 3], true, '250');

    expect($block->getValue())->toBe('250');
});

it('states the optional contract range as the field note', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [
        settingsPathFor('live-key') => accountSettingsRow([
            contractDefinitionItem([
                'options' => capabilityOptions(['insurance' => [
                    'min' => ['amount' => 10000],
                    'max' => ['amount' => 250000],
                ]]),
            ]),
        ]),
    ]);

    expect($block->getNote())
        ->toContain('100')
        ->toContain('2500')
        ->toContain('Enter 0 to switch insurance off.');
});

it('says insurance cannot be switched off when the contract requires it', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, [
        settingsPathFor('live-key') => accountSettingsRow([
            contractDefinitionItem([
                'options' => capabilityOptions(['insurance' => [
                    'isRequired' => true,
                    'min'        => ['amount' => 10000],
                    'max'        => ['amount' => 250000],
                ]]),
            ]),
        ]),
    ]);

    expect($block->getNote())
        ->toContain('100')
        ->toContain('2500')
        ->toContain('cannot be switched off')
        ->not->toContain('Enter 0');
});

it('says any amount is accepted when no bound could be resolved', function () {
    $block = insuranceAmountBlock(POSTNL_LOCAL_AMOUNT_PATH, []);

    expect($block->getNote())
        ->toContain('could not be retrieved')
        ->not->toContain('Your contract allows');
});
