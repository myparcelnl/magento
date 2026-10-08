<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * What the modal warns about: options on for the order that the carrier does not offer for the
 * shipment, which the export leaves off.
 *
 * @param string[] $on options DefaultOptions switches on
 */
function droppedOptionsFor(CapabilitySet $capabilities, array $on, int $insurance = 0): array
{
    $form = createShipmentOptionsFormWith($capabilities);

    $defaults = Mockery::mock(DefaultOptions::class);
    $defaults->shouldReceive('hasOptionSet')->andReturnUsing(static fn(string $option): bool => in_array($option, $on, true));
    $defaults->shouldReceive('getDefaultInsurance')->andReturn($insurance);
    setPrivateProperty($form, 'defaultOptions', $defaults);

    return $form->getDroppedOptions('postnl', 'package');
}

it('names the options on for the order that the carrier does not offer', function () {
    // capabilityResult() offers signature, only recipient and insurance.
    $capabilities = CapabilitySet::fromApiResults([capabilityResult()]);

    expect(droppedOptionsFor($capabilities, [ShipmentOption::AGE_CHECK, ShipmentOption::SIGNATURE]))
        ->toBe([ShipmentOption::labelFor(ShipmentOption::AGE_CHECK)]);
});

it('names insurance when the order would be insured and the carrier does not offer it', function () {
    $options = capabilityOptions();
    unset($options['insurance']);

    expect(droppedOptionsFor(CapabilitySet::fromApiResults([capabilityResult(['options' => $options])]), [], 500))
        ->toBe([ShipmentOption::labelFor(ShipmentOption::INSURANCE)]);
});

it('names nothing while the capabilities are unverified', function () {
    expect(droppedOptionsFor(CapabilitySet::permissive(), [ShipmentOption::AGE_CHECK]))->toBe([]);
});
