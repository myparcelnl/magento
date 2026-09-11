<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

// createShipmentOptions() lives in Tests/Helpers/ShipmentOptionsResolverFixtures.php.

/**
 * Receipt code is standard delivery only. The country and carrier are the API's business.
 */
function receiptCodeFor(string $deliveryType, string $countryId = 'NL', string $carrier = CarrierPostNL::NAME): bool
{
    return createShipmentOptions(
        $countryId,
        $carrier,
        [ShipmentOption::RECEIPT_CODE => true],
        false,
        ['deliveryType' => $deliveryType]
    )->hasReceiptCode();
}

it('allows receipt code on standard delivery', function () {
    expect(receiptCodeFor(DeliveryType::STANDARD_NAME))->toBeTrue();
});

it('refuses receipt code on every non-standard delivery type', function (string $deliveryType) {
    expect(receiptCodeFor($deliveryType))->toBeFalse();
})->with([
    DeliveryType::MORNING_NAME,
    DeliveryType::EVENING_NAME,
    DeliveryType::PICKUP_NAME,
    DeliveryType::SAME_DAY_NAME,
    DeliveryType::EXPRESS_NAME,
    DeliveryType::EARLY_MORNING_NAME,
]);

it('treats an order without stored delivery options as standard', function () {
    // The admin New Shipment form creates shipments for orders that never passed the widget.
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [
        ShipmentOption::RECEIPT_CODE => true,
    ]);

    expect($shipmentOptions->hasReceiptCode())->toBeTrue();
});

/**
 * The country and carrier gates are gone; the delivery-type one stays.
 *
 * Which carrier offers receipt code where is the API's answer — the same reasoning hasAgeCheck()
 * already carried — and per docs/sdk-v11.md the API is the validator. The delivery-type rule is the
 * module's own, because capabilities answer per carrier and package type, never per delivery type.
 */
it('leaves country and carrier to the api, and keeps only the delivery-type rule', function () {
    expect(receiptCodeFor(DeliveryType::STANDARD_NAME, 'BE'))->toBeTrue()
        ->and(receiptCodeFor(DeliveryType::STANDARD_NAME, 'NL', 'dhlforyou'))->toBeTrue()
        ->and(receiptCodeFor(DeliveryType::EVENING_NAME, 'BE'))->toBeFalse();
});

it('refuses receipt code when it was explicitly declined', function () {
    // The fallback is forced to true, so false here can only come from the explicit choice.
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [
        ShipmentOption::RECEIPT_CODE => false,
    ], true, ['deliveryType' => DeliveryType::STANDARD_NAME]);

    expect($shipmentOptions->hasReceiptCode())->toBeFalse();
});

it('falls back to the configured default when the live options carry no choice', function () {
    $shipmentOptions = createShipmentOptions(
        'NL',
        CarrierPostNL::NAME,
        [],
        true,
        ['deliveryType' => DeliveryType::STANDARD_NAME]
    );

    expect($shipmentOptions->hasReceiptCode())->toBeTrue();
});

it('refuses receipt code without a choice anywhere', function () {
    $shipmentOptions = createShipmentOptions(
        'NL',
        CarrierPostNL::NAME,
        [],
        false,
        ['deliveryType' => DeliveryType::STANDARD_NAME]
    );

    expect($shipmentOptions->hasReceiptCode())->toBeFalse();
});
