<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * resolve() is the builder's single source of shipment options, and the builder
 * writes them into setters that reject null. ShipmentOptions::of() is a
 * bare constructor call that enforces nothing, and every getter is declared
 * nullable, so the non-null guarantee is a convention held here and nowhere
 * else. These tests are what make it a contract.
 */
it('resolves every option to a non-null value', function () {
    $resolved = createShipmentOptions(CountryCode::CC_NL, CarrierPostNL::NAME, [])->resolve()->toArray();

    expect($resolved)->not->toContain(null)
        ->and(array_keys($resolved))->toHaveCount(14);
});

it('resolves every option to a non-null value outside NL too', function () {
    // The country gates (age check, receipt code, priority delivery, large format)
    // take their other branch here, which is where a null would hide.
    $resolved = createShipmentOptions('DE', CarrierPostNL::NAME, [])->resolve()->toArray();

    expect($resolved)->not->toContain(null);
});

it('types every resolved option as the setters expect', function () {
    $resolved = createShipmentOptions(CountryCode::CC_NL, CarrierPostNL::NAME, [])->resolve();

    expect($resolved->getInsurance())->toBeInt()
        ->and($resolved->getLabelDescription())->toBeString()
        ->and($resolved->option(ShipmentOption::SIGNATURE))->toBeBool()
        ->and($resolved->option(ShipmentOption::AGE_CHECK))->toBeBool()
        ->and($resolved->option(ShipmentOption::RECEIPT_CODE))->toBeBool()
        ->and($resolved->option(ShipmentOption::PRIORITY_DELIVERY))->toBeBool()
        ->and($resolved->option(ShipmentOption::FRESH_FOOD))->toBeBool()
        ->and($resolved->option(ShipmentOption::FROZEN))->toBeBool();
});
