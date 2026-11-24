<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;

it('uses only the facts having() and fact() read', function () {
    $class = new ReflectionClass(ShipmentOption::class);
    $known = array_merge($class->getConstant('FLAGS'), $class->getConstant('TEXTS'));

    foreach (ShipmentOption::DEFINITIONS as $option => $row) {
        expect(array_diff(array_keys($row), $known))->toBe([], "'$option' has a fact nothing reads");
    }
});

it('refuses a fact it does not know', function () {
    ShipmentOption::having('fee');
})->throws(InvalidArgumentException::class);

it('reads every option fee from a setting the merchant can fill in', function () {
    // A fee path without a field reads as 0, so the checkout and the order both lose the fee in silence.
    $settings = json_decode(file_get_contents(dirname(__DIR__, 4) . '/etc/dynamic_settings.json'), true);
    $fields   = [];

    foreach ($settings['sections'] as $section) {
        foreach ($section['groups'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $fields[] = $field['path'] ?? '';
            }
        }
    }

    foreach (ShipmentOption::feePaths() as $option => $feePath) {
        $carriers = array_filter(
            Config::CARRIERS_XML_PATH_MAP,
            static fn (string $carrierPath): bool => in_array($carrierPath . $feePath, $fields, true)
        );

        expect($carriers)->not->toBeEmpty("'$option' reads $feePath, which no carrier's settings have");
    }
});
