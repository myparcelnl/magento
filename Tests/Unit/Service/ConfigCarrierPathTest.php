<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

it('derives every legacy carrier path from the carrier name', function () {
    foreach (legacyCarriers() as $carrier) {
        $path = Config::carrierPath($carrier);

        expect($path)->toBe("myparcelnl_magento_{$carrier}_settings/")
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
