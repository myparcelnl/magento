<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;

/**
 * Every checkbox the New Shipment form can render, from the POST to the shipment the API receives.
 *
 * The form is built from ShipmentOption::TO_CHECK; the export once named each option by hand, so a
 * new one showed as a checkbox and was dropped without a trace. NL, standard delivery and a
 * package: no option has a rule that turns it off there.
 */
function sdkOptionsFor(ResolvedOptions $resolved, array $storedDeliveryOptions = []): SdkShipmentOptions
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn(PackageType::PACKAGE);

    return createOrderShipmentOptions([
        'options'         => [],
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromOrderFallback($storedDeliveryOptions),
        'resolved'        => $resolved,
    ])->shipmentOptions(createAddress(['getCountryId' => 'NL']));
}

function sdkValueOf(SdkShipmentOptions $options, string $option)
{
    return $options->{SdkShipmentOptions::getters()[$option]}();
}

it('reads the option out of the posted parameters', function (string $option) {
    expect(optionsFromParams(['mypa_' . $option => '1'])[$option] ?? null)->toBe('1');
})->with(ShipmentOption::TO_CHECK);

it('resolves the option from the posted options', function (string $option) {
    $resolved = createShipmentOptions('NL', Carrier::POSTNL, [$option => '1'])->resolve();

    expect($resolved->toArray()[$option] ?? null)->toBeTrue();
})->with(ShipmentOption::TO_CHECK);

it('sets the option on the shipment the API receives', function (string $option) {
    expect(sdkValueOf(sdkOptionsFor(ResolvedOptions::of([$option => true])), $option))->toBe(1);
})->with(ShipmentOption::TO_CHECK);

it('sends a literal 0 when the option is not chosen', function (string $option) {
    // RefTypesIntBoolean is checked against [0, 1] with a strict in_array, so false would throw.
    expect(sdkValueOf(sdkOptionsFor(ResolvedOptions::of([])), $option))->toBe(0);
})->with(ShipmentOption::TO_CHECK);

it('never sends return on a pickup, whatever was chosen', function () {
    $options = sdkOptionsFor(
        ResolvedOptions::of([ShipmentOption::RETURN => true]),
        ['deliveryType' => DeliveryType::PICKUP_NAME]
    );

    expect($options->getReturn())->toBe(0);
});
