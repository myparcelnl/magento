<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Validator\ExportableCarrier;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\Config;

it('claims only the activation toggles of a carrier', function (string $path, bool $handles) {
    expect((new ExportableCarrier())->handles($path))->toBe($handles);
})->with([
    'delivery'      => [Config::carrierPath('hoopla') . 'delivery/active', true],
    'pickup'        => [Config::carrierPath('hoopla') . 'pickup/active', true],
    'an option'     => [Config::carrierPath('hoopla') . 'delivery/signature_active', false],
    'mailbox'       => [Config::carrierPath('hoopla') . 'mailbox/active', false],
    'general'       => [Config::XML_PATH_GENERAL . 'api/key', false],
]);

it('refuses to switch on a carrier the module cannot export', function () {
    $rejection = (new ExportableCarrier())->validate(Config::carrierPath('hoopla') . 'delivery/active', '1', 'default', 0);

    expect((string) $rejection)->toContain('hoopla')->toContain('module update');
});

it('lets a carrier the module cannot export be switched off', function () {
    expect((new ExportableCarrier())->validate(Config::carrierPath('hoopla') . 'pickup/active', '0', 'default', 0))->toBeNull();
});

it('lets every carrier the SDK knows be switched on', function () {
    foreach (array_merge(legacyCarriers(), ['cheapcargo', 'upsexpresssaver']) as $carrier) {
        expect(Carrier::isExportable($carrier))->toBeTrue($carrier)
            ->and((new ExportableCarrier())->validate(Config::carrierPath($carrier) . 'delivery/active', '1', 'default', 0))
            ->toBeNull();
    }
});
