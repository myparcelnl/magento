<?php

declare(strict_types=1);

use Magento\Config\Model\Config\PathValidator;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Plugin\Magento\Config\GeneratedSettingsPathValidator;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Settings;

const STORE_ONLY_PATH = 'myparcelnl_magento_postnl_settings/delivery/active';

/**
 * The plugin with one website (1) and one store (2). Only the store's form offers STORE_ONLY_PATH,
 * and every form offers $everywhere.
 *
 * @param string[] $everywhere
 */
function pathValidatorPlugin(array $everywhere = []): GeneratedSettingsPathValidator
{
    $settings = Mockery::mock(Settings::class);
    $settings->shouldReceive('getAllFieldPaths')->with('default', 0)->andReturn($everywhere);
    $settings->shouldReceive('getAllFieldPaths')->with('websites', 1)->andReturn($everywhere);
    $settings->shouldReceive('getAllFieldPaths')->with('stores', 2)->andReturn(array_merge($everywhere, [STORE_ONLY_PATH]));

    return new GeneratedSettingsPathValidator($settings, Mockery::mock(StoreManagerInterface::class, [
        'getWebsites' => [Mockery::mock(['getId' => '1'])],
        'getStores'   => [Mockery::mock(['getId' => '2'])],
    ]));
}

/** Validates $path, and says whether Magento's own validator was asked. */
function validateWithPlugin(GeneratedSettingsPathValidator $plugin, string $path): bool
{
    $proceeded = false;

    $plugin->aroundValidate(Mockery::mock(PathValidator::class), static function () use (&$proceeded): bool {
        $proceeded = true;

        return true;
    }, $path);

    return $proceeded;
}

it('accepts a path that the form offers at any one scope', function () {
    expect(validateWithPlugin(pathValidatorPlugin(), STORE_ONLY_PATH))->toBeFalse();
});

it('leaves a path no form offers to Magento', function () {
    expect(validateWithPlugin(pathValidatorPlugin(), 'myparcelnl_magento_general/api/kye'))->toBeTrue();
});

it('leaves the stored account row and the token hash to Magento, even when the form offers them', function (string $path) {
    expect(validateWithPlugin(pathValidatorPlugin([$path]), $path))->toBeTrue();
})->with([
    'account row' => [Config::XML_PATH_ACCOUNT_SETTINGS . 'abc123'],
    'token hash'  => [TokenService::CONFIG_PATH],
]);

it('builds no form for a path outside the MyParcel sections', function () {
    $plugin = new GeneratedSettingsPathValidator(
        Mockery::mock(Settings::class)->shouldNotReceive('getAllFieldPaths')->getMock(),
        Mockery::mock(StoreManagerInterface::class)
    );

    expect(validateWithPlugin($plugin, 'general/locale/code'))->toBeTrue();
});
