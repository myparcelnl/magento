<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

it('derives every configured carrier path from the carrier name', function () {
    foreach (Config::CARRIERS_XML_PATH_MAP as $carrier => $path) {
        expect(Config::carrierPath($carrier))->toBe($path)
            ->and(Config::carrierFromPath($path . 'delivery/active'))->toBe($carrier);
    }
});

it('derives a path for a carrier the module has no settings for', function () {
    expect(Config::carrierPath('hoopla'))->toBe('myparcelnl_magento_hoopla_settings/')
        ->and(Config::carrierFromPath('myparcelnl_magento_hoopla_settings/delivery/active'))->toBe('hoopla');
});

it('names no carrier for a path outside a carrier section', function (string $path) {
    expect(Config::carrierFromPath($path))->toBeNull();
})->with([
    'general'        => 'myparcelnl_magento_general/print/export_mode',
    'another module' => 'carriers/flatrate/active',
    'not a prefix'   => 'x_myparcelnl_magento_postnl_settings/delivery/active',
]);
