<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;

/**
 * Builds the resolver against mocked collaborators. Shared because the country and carrier guards
 * differ per option, so each option gets its own test file over the same construction.
 */
function createShipmentOptions(
    string $countryId,
    string $carrier,
    array  $options,
    bool   $defaultOptionSet = false,
    ?array $storedDeliveryOptions = null
): ShipmentOptionsResolver
{
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn($countryId);

    $order = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn($address);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn(Mockery::mock(Config::class));

    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('hasOptionSet')->andReturn($defaultOptionSet)->byDefault();

    return new ShipmentOptionsResolver(
        $defaultOptions,
        $order,
        storedDeliveryOptions($storedDeliveryOptions),
        $objectManager,
        $carrier,
        $options
    );
}

/** Null means an order carrying no delivery options, which the resolver reads as standard. */
function storedDeliveryOptions(?array $stored): DeliveryOptions
{
    if (null === $stored) {
        return DeliveryOptions::defaults();
    }

    // A pickup without a location is refused, which is noise for a test that only varies the type.
    if (DeliveryType::PICKUP_NAME === ($stored['deliveryType'] ?? null) && ! isset($stored['pickupLocation'])) {
        $stored['pickupLocation'] = [
            'location_name' => 'Test point',
            'location_code' => '1',
            'street'        => 'Teststraat',
            'number'        => '1',
            'postal_code'   => '1234AB',
            'city'          => 'Testdorp',
            'cc'            => 'NL',
        ];
    }

    return DeliveryOptionsFactory::create($stored);
}
