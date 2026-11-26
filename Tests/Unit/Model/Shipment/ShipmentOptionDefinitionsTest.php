<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Blueprint\Generator;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
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
    // The form of a PostNL contract that offers every option with a fee, mailbox included.
    $capabilities = CapabilitySet::fromContractDefinitionItems([contractDefinitionItem([
        'carrier'       => 'POSTNL',
        'packageTypes'  => array_map([PackageType::class, 'toV2Name'], ['package', 'mailbox']),
        'deliveryTypes' => array_map([DeliveryType::class, 'toV2Name'], ['standard']),
        'options'       => array_fill_keys(array_map([ShipmentOption::class, 'toV2Name'], array_keys(ShipmentOption::feePaths())), []),
    ])]);
    $paths = Generator::for($capabilities)->paths();

    foreach (ShipmentOption::feePaths() as $option => $feePath) {
        expect(in_array(Config::carrierPath('postnl') . $feePath, $paths, true))
            ->toBeTrue("'$option' reads $feePath, which the settings form does not offer");
    }
});
