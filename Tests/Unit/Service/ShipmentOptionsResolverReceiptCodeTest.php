<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\OptionSource;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

// createShipmentOptions() lives in Tests/Helpers/ShipmentOptionsResolverFixtures.php.

/**
 * Receipt code on a delivery type is the capabilities' answer for that delivery type. The module has
 * no rule of its own.
 *
 * @param array<string,mixed> $offered the capabilities `options` for the asked delivery type
 */
function receiptCodeResolved(string $deliveryType, array $offered, ?array &$history = null): bool
{
    // A dropped option is logged.
    captureNotices();

    $doubles = makeCapabilitiesRepository([
        new GuzzleResponse(200, [], capabilitiesBody([
            capabilityResult(['packageTypes' => ['PACKAGE'], 'options' => $offered]),
        ])),
    ]);

    $resolved = createShipmentOptions(
        'NL',
        'postnl',
        [],
        false,
        ['deliveryType' => $deliveryType, 'packageType' => PackageType::PACKAGE_NAME],
        $doubles['repository'],
        dependencyDefaultOptions([ShipmentOption::RECEIPT_CODE => OptionSource::CHECKOUT])
    )->resolve()->toArray()[ShipmentOption::RECEIPT_CODE];

    $history = $doubles['history'];

    return $resolved;
}

function offeringReceiptCode(): array
{
    return capabilityOptions(['requiresReceiptCode' => ['isRequired' => false, 'isSelectedByDefault' => false]]);
}

it('drops receipt code when capabilities for the delivery type do not offer it', function () {
    expect(receiptCodeResolved(DeliveryType::MORNING_NAME, capabilityOptions()))->toBeFalse();
});

it('keeps receipt code when capabilities for the delivery type offer it', function () {
    expect(receiptCodeResolved(DeliveryType::STANDARD_NAME, offeringReceiptCode()))->toBeTrue();
});

it('keeps receipt code on a non-standard delivery type when capabilities offer it', function () {
    expect(receiptCodeResolved(DeliveryType::EVENING_NAME, offeringReceiptCode()))->toBeTrue();
});

it('asks capabilities with the stored delivery type', function () {
    receiptCodeResolved(DeliveryType::MORNING_NAME, capabilityOptions(), $history);

    expect((string) $history[0]['request']->getBody())->toContain(DeliveryType::toV2Name(DeliveryType::MORNING_NAME));
});

it('treats an order without stored delivery options as chosen', function () {
    // The admin New Shipment form creates shipments for orders that never passed the widget.
    $shipmentOptions = createShipmentOptions('NL', 'postnl', [
        ShipmentOption::RECEIPT_CODE => true,
    ]);

    expect($shipmentOptions->hasReceiptCode())->toBeTrue();
});
