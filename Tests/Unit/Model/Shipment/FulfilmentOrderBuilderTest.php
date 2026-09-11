<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Shipment\Carrier as SdkCarrier;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;

/**
 * Drives the real build(), which is where beta.31's two fulfilment changes land: the carrier moved
 * out of the delivery options into its own field, and the options are now the SDK's shipment
 * options rather than a delivery-options adapter.
 */
function pickupCheckoutData(): array
{
    return [
        'carrier'        => CarrierDHLForYou::NAME,
        'deliveryType'   => 'pickup',
        'isPickup'       => true,
        'date'           => '2026-08-20',
        'packageType'    => 'mailbox',
        'pickupLocation' => [
            'location_name'     => 'Shop',
            'location_code'     => 'X1',
            'retail_network_id' => 'RN1',
            'street'            => 'Main',
            'number'            => '1',
            'postal_code'       => '1000AA',
            'city'              => 'Berlin',
            'cc'                => 'DE',
        ],
    ];
}

it('sets the carrier id, so getCarrier() no longer throws', function () {
    // beta.31 made setCarrierId() mandatory: carrier is shipment-level data in the Order v1 payload
    // and getCarrier() throws when it was never set. Nothing else in the module would notice.
    $order = createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder(['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard']),
        []
    );

    expect($order->getCarrierId())->toBe(SdkCarrier::toId('POSTNL'))
        ->and($order->getCarrier()->getName())->toBe(CarrierPostNL::NAME);
});

it('hands the fulfilment order the SDK shipment options', function () {
    $order = createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder([
            'carrier'      => CarrierPostNL::NAME,
            'deliveryType' => 'standard',
            'packageType'  => 'mailbox',
        ]),
        []
    );

    expect($order->getDeliveryOptions())->toBeInstanceOf(SdkShipmentOptions::class)
        ->and($order->getDeliveryOptions()->getPackageType())->toBe(PackageType::MAILBOX);
});

it('maps the pickup location field for field', function () {
    $order = createFulfilmentOrderBuilder()
        ->build(createFulfilmentMagentoOrder(pickupCheckoutData()), ['carrier' => CarrierDHLForYou::NAME]);

    $pickup = $order->getPickupLocation();

    expect($pickup->getCc())->toBe('DE')
        ->and($pickup->getCity())->toBe('Berlin')
        ->and($pickup->getPostalCode())->toBe('1000AA')
        ->and($pickup->getStreet())->toBe('Main')
        ->and($pickup->getNumber())->toBe('1')
        ->and($pickup->getLocationName())->toBe('Shop')
        ->and($pickup->getLocationCode())->toBe('X1')
        ->and($pickup->getRetailNetworkId())->toBe('RN1');
});

it('leaves a pickup on the package type the checkout stored', function () {
    // This path used to overwrite the package type with `package` for every pickup, contradicting
    // a checkout that had offered a mailbox pickup. Both export paths now answer the same.
    $order = createFulfilmentOrderBuilder()
        ->build(createFulfilmentMagentoOrder(pickupCheckoutData()), ['carrier' => CarrierDHLForYou::NAME]);

    expect($order->getDeliveryOptions()->getPackageType())->toBe(PackageType::MAILBOX);
});

it('resolves the API key from the order\'s own store, not another store\'s', function () {
    // Only store 5 resolves to a key; the order belongs to store 9. A lookup ignoring the order's
    // store would find store 5's key and not throw.
    $builder = createFulfilmentOrderBuilder('store-5-key', 5);
    $order   = createFulfilmentMagentoOrder(
        ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'],
        ['getStoreId' => 9]
    );

    expect(fn () => $builder->build($order, []))->toThrow(LocalizedException::class);
});

it('keeps the checkout carrier when handed the collection\'s default options', function () {
    // The order grid's PPS export passes MagentoCollection's constructor defaults straight through.
    // A carrier literal in those defaults reads as an admin override and replaces the customer's.
    $defaults = newInstanceWithoutConstructor(MagentoOrderCollection::class)->getOptions();

    $order = createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder(['carrier' => CarrierDHLForYou::NAME, 'deliveryType' => 'standard']),
        $defaults
    );

    expect($order->getCarrier()->getName())->toBe(CarrierDHLForYou::NAME);
});

it('keeps the checkout carrier when the modal says default', function () {
    // The grid modal posts mypa_carrier=default unless the admin picks a carrier.
    $order = createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder(['carrier' => CarrierDHLForYou::NAME, 'deliveryType' => 'standard']),
        ['carrier' => DefaultOptions::DEFAULT_OPTION_VALUE]
    );

    expect($order->getCarrier()->getName())->toBe(CarrierDHLForYou::NAME);
});
