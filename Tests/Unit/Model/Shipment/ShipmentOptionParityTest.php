<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\ShipmentOptions\OptionChanges;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;

/**
 * Every checkbox the shipment options form can render, from the POST to the shipment the API receives.
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
        'order'           => createOrder(),
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromOrderFallback($storedDeliveryOptions),
        'resolved'        => $resolved,
    ])->shipmentOptions();
}

function sdkValueOf(SdkShipmentOptions $options, string $option)
{
    return $options->{SdkShipmentOptions::getters()[$option]}();
}

it('reads the option out of the posted changes', function (string $option) {
    expect(OptionChanges::fromRequest(['options' => [$option => '1']])->options()[$option] ?? null)->toBeTrue();
})->with(ShipmentOption::TO_CHECK);

it('resolves the option from the posted options', function (string $option) {
    $resolved = createShipmentOptions('NL', 'postnl', [$option => '1'])->resolve();

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

it('sends an option only capabilities named, when the SDK can set it', function () {
    $options = sdkOptionsFor(ResolvedOptions::resolved(['no_tracking' => true]));

    expect($options->getNoTracking())->toBe(1);
});

it('skips a chosen option the SDK cannot set, and says so', function () {
    mockLoggerFacade()->shouldReceive('notice')->once()->with(Mockery::pattern('/"hovercraft".*100000001/'));

    sdkOptionsFor(ResolvedOptions::resolved(['hovercraft' => true]));
});

it('keeps an option only capabilities named after the persisted keys', function () {
    $keys = array_keys(ResolvedOptions::resolved(['no_tracking' => false])->toArray());

    expect(end($keys))->toBe('no_tracking')
        ->and(ResolvedOptions::resolved(['no_tracking' => false])->discovered())->toBe(['no_tracking' => false]);
});

it('keeps an option a merchant saved that only capabilities name, and drops anything else', function () {
    $stored = ResolvedOptions::of(['no_tracking' => false, 'Not An Option' => true, 'odd' => 'yes'])->toArray();

    expect($stored)->toHaveKey('no_tracking')
        ->and($stored['no_tracking'])->toBeFalse()
        ->and($stored)->not->toHaveKey('Not An Option')
        ->and($stored)->not->toHaveKey('odd');
});

it('takes no shipment option from an export request: the order holds them', function () {
    $options = optionsFromParams(['mypa_signature' => '1', 'mypa_label_amount' => '5', 'mypa_carrier' => 'dpd']);

    expect(array_keys($options))->toBe(['create_track_if_one_already_exist', 'request_type', 'positions']);
});
