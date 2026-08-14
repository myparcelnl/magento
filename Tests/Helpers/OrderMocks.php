<?php

declare(strict_types=1);

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Service\Config;

/**
 * Builds a Mockery double of an Order\Address with sane defaults for every
 * field TrackTraceHolder and MagentoOrderCollection read off it.
 *
 * `street` accepts one line as a string or several as an array — Magento
 * stores one array entry per street line. It sets both getStreet() (the
 * exploded-array view MagentoOrderCollection::setShippingRecipient() reads)
 * and getData('street') (the joined string TrackTraceHolder::setFullStreet()
 * reads), so a test only has to state the address once.
 */
function createAddress(array $overrides = []): Address
{
    $street = $overrides['street'] ?? 'Hoofdstraat 1';
    unset($overrides['street']);
    $streetLines = (array) $street;

    $defaults = [
        'getCountryId'  => 'NL',
        'getCompany'    => null,
        'getName'       => 'Jan Jansen',
        'getPostcode'   => '1234AB',
        'getCity'       => 'Amsterdam',
        'getRegionCode' => null,
        'getTelephone'  => null,
        'getEmail'      => null,
        'getFirstname'  => null,
        'getMiddlename' => null,
        'getLastname'   => null,
        'getStreet'     => $streetLines,
    ];

    $address = Mockery::mock(Address::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $address->shouldReceive($method)->andReturn($value)->byDefault();
    }
    $address->shouldReceive('getData')->with('street')->andReturn(implode(' ', $streetLines))->byDefault();

    return $address;
}

/**
 * A real Magento\Framework\DataObject, not a Mockery mock: it genuinely
 * supports both array access ($item['product_id']) and magic getters
 * ($item->getProductId()), which is exactly how TrackTraceHolder's two
 * customs loops read shipment items (see TrackTraceHolderCustomsTest).
 */
function createShipmentItem(array $data = []): \Magento\Framework\DataObject
{
    return new \Magento\Framework\DataObject(array_merge([
        'name'       => 'Test product',
        'qty'        => 1,
        'weight'     => 100.0,
        'price'      => 10.0,
        'product_id' => 1,
    ], $data));
}

/**
 * `items` (if given) backs BOTH getItems() and getData('items') with the
 * same array. TrackTraceHolder::convertDataForCdCountry() reads shipment
 * items through both methods, and the two must agree for a faithful fixture.
 */
function createShipment(array $overrides = []): Shipment
{
    $items = $overrides['items'] ?? [];
    unset($overrides['items']);

    $defaults = [
        'getShippingAddress' => createAddress(),
        'getOrder'           => null,
        'getItems'           => $items,
        'getEntityId'        => 123,
    ];

    $shipment = Mockery::mock(Shipment::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $shipment->shouldReceive($method)->andReturn($value)->byDefault();
    }
    $shipment->shouldReceive('getData')->with('items')->andReturn($items)->byDefault();

    return $shipment;
}

/**
 * `deliveryOptions` (a JSON string, as actually stored on the order) backs
 * getData(Config::FIELD_DELIVERY_OPTIONS) — the checkout payload
 * TrackTraceHolder, ShipmentOptions and DefaultOptions all read from a real
 * order this way.
 */
function createOrder(array $overrides = []): Order
{
    $deliveryOptionsJson = $overrides['deliveryOptions'] ?? '[]';
    unset($overrides['deliveryOptions']);

    $defaults = [
        'getStoreId'         => 1,
        'getIncrementId'     => '100000001',
        'getShippingAddress' => null,
        'getBillingAddress'  => null,
        'getItems'           => [],
        'getId'              => 1,
        'getGrandTotal'      => 0.0,
    ];

    $order = Mockery::mock(Order::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $order->shouldReceive($method)->andReturn($value)->byDefault();
    }
    $order->shouldReceive('getData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturn($deliveryOptionsJson)->byDefault();

    return $order;
}

/**
 * `consignmentId` backs getData('myparcel_consignment_id'), the one keyed
 * getData() call TrackTraceHolder makes directly on a track.
 */
function createShipmentTrack(array $overrides = []): Track
{
    $consignmentId = $overrides['consignmentId'] ?? null;
    unset($overrides['consignmentId']);

    $defaults = [
        'getShipment' => null,
        'getOrderId'  => 1,
    ];

    $track = Mockery::mock(Track::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $track->shouldReceive($method)->andReturn($value)->byDefault();
    }
    $track->shouldReceive('getData')->with('myparcel_consignment_id')->andReturn($consignmentId)->byDefault();

    return $track;
}
