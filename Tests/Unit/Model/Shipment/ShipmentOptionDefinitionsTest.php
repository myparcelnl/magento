<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

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
