<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Shipment\Carrier as SdkCarrier;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;

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

it('sets the carrier id, so getCarrier() does not throw', function () {
    // getCarrier() throws when the carrier was never set, and nothing else in the module checks it.
    $order = createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder(['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'])
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
        ])
    );

    expect($order->getDeliveryOptions())->toBeInstanceOf(SdkShipmentOptions::class)
        ->and($order->getDeliveryOptions()->getPackageType())->toBe(PackageType::MAILBOX);
});

it('maps the pickup location field for field', function () {
    $order = createFulfilmentOrderBuilder()
        ->build(createFulfilmentMagentoOrder(pickupCheckoutData()));

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
    $order = createFulfilmentOrderBuilder()
        ->build(createFulfilmentMagentoOrder(pickupCheckoutData()));

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

    expect(fn () => $builder->build($order))->toThrow(LocalizedException::class);
});

it('sends the dimensions a merchant saved, and the SDK default without them', function () {
    $saved = createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder([
            'carrier'            => CarrierPostNL::NAME,
            'deliveryType'       => 'standard',
            'physicalProperties' => ['length' => 40, 'width' => 30, 'height' => 20],
        ])
    )->getPhysicalProperties();

    $default = createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder(['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'])
    )->getPhysicalProperties();

    expect([$saved->getLength(), $saved->getWidth(), $saved->getHeight()])->toBe([40, 30, 20])
        ->and([$default->getLength(), $default->getWidth(), $default->getHeight()])->toBe([10, 10, 10]);
});
