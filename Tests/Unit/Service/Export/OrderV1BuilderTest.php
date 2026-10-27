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
    $order = createOrderV1Builder()->build(
        createOrderV1MagentoOrder(['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'])
    );

    expect($order->getCarrierId())->toBe(SdkCarrier::toId('POSTNL'))
        ->and($order->getCarrier()->getName())->toBe(CarrierPostNL::NAME);
});

it('hands the fulfilment order the SDK shipment options', function () {
    $order = createOrderV1Builder()->build(
        createOrderV1MagentoOrder([
            'carrier'      => CarrierPostNL::NAME,
            'deliveryType' => 'standard',
            'packageType'  => 'mailbox',
        ])
    );

    expect($order->getDeliveryOptions())->toBeInstanceOf(SdkShipmentOptions::class)
        ->and($order->getDeliveryOptions()->getPackageType())->toBe(PackageType::MAILBOX);
});

it('maps the pickup location field for field', function () {
    $order = createOrderV1Builder()
        ->build(createOrderV1MagentoOrder(pickupCheckoutData()));

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
    $order = createOrderV1Builder()
        ->build(createOrderV1MagentoOrder(pickupCheckoutData()));

    expect($order->getDeliveryOptions()->getPackageType())->toBe(PackageType::MAILBOX);
});

it('resolves the API key from the order\'s own store, not another store\'s', function () {
    // Only store 5 resolves to a key; the order belongs to store 9. A lookup ignoring the order's
    // store would find store 5's key and not throw.
    $builder = createOrderV1Builder('store-5-key', 5);
    $order   = createOrderV1MagentoOrder(
        ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'],
        ['getStoreId' => 9]
    );

    expect(fn () => $builder->build($order))->toThrow(LocalizedException::class);
});

it('weighs the rows a label ships, as the customs declaration does', function () {
    // A configurable's child row repeats the parent's weight; a virtual row has none to ship.
    $parent  = createOrderItem(['item_id' => 1, 'weight' => 200.0]);
    $child   = createOrderItem(['item_id' => 2, 'weight' => 200.0, 'parent_item' => $parent, 'is_dummy' => true]);
    $virtual = createOrderItem(['item_id' => 3, 'weight' => 50.0, 'is_virtual' => true]);

    $order = createOrderV1Builder()->build(
        createOrderV1MagentoOrder(
            ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'],
            ['getItems' => [$parent, $child, $virtual]]
        ),
        []
    );

    expect($order->getWeight())->toBe(200);
});

it('sends the dimensions a merchant saved, and the SDK default without them', function () {
    $saved = createOrderV1Builder()->build(
        createOrderV1MagentoOrder([
            'carrier'            => CarrierPostNL::NAME,
            'deliveryType'       => 'standard',
            'physicalProperties' => ['length' => 40, 'width' => 30, 'height' => 20],
        ])
    )->getPhysicalProperties();

    $default = createOrderV1Builder()->build(
        createOrderV1MagentoOrder(['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'])
    )->getPhysicalProperties();

    expect([$saved->getLength(), $saved->getWidth(), $saved->getHeight()])->toBe([40, 30, 20])
        ->and([$default->getLength(), $default->getWidth(), $default->getHeight()])->toBe([10, 10, 10]);
});
