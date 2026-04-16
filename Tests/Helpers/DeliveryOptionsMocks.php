<?php

declare(strict_types=1);

use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractPickupLocationAdapter;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;

function mockShipmentOptions(array $overrides = []): AbstractShipmentOptionsAdapter
{
    $defaults = [
        'hasAgeCheck'        => false,
        'hasSignature'       => false,
        'hasOnlyRecipient'   => false,
        'hasLargeFormat'     => false,
        'isReturn'           => false,
        'hasHideSender'      => false,
        'isPriorityDelivery' => false,
        'hasReceiptCode'     => false,
        'isSameDayDelivery'  => false,
        'hasCollect'         => false,
        'getInsurance'       => null,
        'getLabelDescription' => null,
    ];

    $mock = Mockery::mock(AbstractShipmentOptionsAdapter::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $mock->shouldReceive($method)->andReturn($value);
    }

    return $mock;
}

function mockPickupLocation(array $overrides = []): AbstractPickupLocationAdapter
{
    $defaults = [
        'getLocationCode'    => 'LOC-1',
        'getLocationName'    => 'Test location',
        'getRetailNetworkId' => 'NET-1',
        'getStreet'          => 'Main street',
        'getNumber'          => '42',
        'getPostalCode'      => '1234AB',
        'getCity'            => 'Amsterdam',
        'getCountry'         => 'NL',
    ];

    $mock = Mockery::mock(AbstractPickupLocationAdapter::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $mock->shouldReceive($method)->andReturn($value);
    }

    return $mock;
}

function mockFullAdapter(array $shipmentOverrides = []): AbstractDeliveryOptionsAdapter
{
    $mock = Mockery::mock(AbstractDeliveryOptionsAdapter::class);
    $mock->shouldReceive('getCarrier')->andReturn('postnl');
    $mock->shouldReceive('getPackageType')->andReturn('package');
    $mock->shouldReceive('getDeliveryType')->andReturn('standard');
    $mock->shouldReceive('getDate')->andReturn('2025-03-15');
    $mock->shouldReceive('getShipmentOptions')->andReturn(mockShipmentOptions(array_merge([
        'hasSignature'       => true,
        'hasOnlyRecipient'   => true,
        'getInsurance'       => 50,
        'getLabelDescription' => 'PO-12345',
    ], $shipmentOverrides)));
    $mock->shouldReceive('getPickupLocation')->andReturn(mockPickupLocation());

    return $mock;
}

function mockMinimalAdapter(): AbstractDeliveryOptionsAdapter
{
    $mock = Mockery::mock(AbstractDeliveryOptionsAdapter::class);
    $mock->shouldReceive('getCarrier')->andReturn(null);
    $mock->shouldReceive('getPackageType')->andReturn(null);
    $mock->shouldReceive('getDeliveryType')->andReturn(null);
    $mock->shouldReceive('getDate')->andReturn(null);
    $mock->shouldReceive('getShipmentOptions')->andReturn(null);
    $mock->shouldReceive('getPickupLocation')->andReturn(null);

    return $mock;
}
