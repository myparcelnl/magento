<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

// createShipmentOptions() lives in Tests/Helpers/ShipmentOptionsResolverFixtures.php.

/**
 * Receipt code is PostNL, NL and standard delivery only. Each guard is pinned separately, because
 * the delivery type one silently passed everything until DR-14.
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

it('refuses receipt code for non-NL destinations', function () {
    expect(receiptCodeFor(DeliveryType::STANDARD_NAME, 'BE'))->toBeFalse();
});

it('refuses receipt code for non-PostNL carriers', function () {
    expect(receiptCodeFor(DeliveryType::STANDARD_NAME, 'NL', 'dhlforyou'))->toBeFalse();
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
