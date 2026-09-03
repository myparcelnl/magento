<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;

/**
 * Builds the resolver against mocked collaborators. Shared because the country and carrier guards
 * differ per option, so each option gets its own test file over the same construction.
 */
function createShipmentOptions(
    string                  $countryId,
    string                  $carrier,
    array                   $options,
    bool                    $defaultOptionSet = false,
    ?array                  $storedDeliveryOptions = null,
    ?CapabilitiesRepository $capabilities = null,
    ?DefaultOptions         $defaultOptions = null
): ShipmentOptionsResolver
{
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn($countryId);

    $order = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn($address);
    $order->shouldReceive('getStoreId')->andReturn(1)->byDefault();
    $order->shouldReceive('getIncrementId')->andReturn('100000001')->byDefault();
    $order->shouldReceive('getId')->andReturn(1)->byDefault();
    $order->shouldReceive('getItems')->andReturn([])->byDefault();

    // Answers null for every setting, so resolve() reaches its own defaults rather than
    // a BadMethodCallException. An option that needs a real setting stubs it per test.
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')->andReturnNull()->byDefault();
    $config->shouldReceive('getCarrierConfig')->andReturnNull()->byDefault();

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn($config);

    // Absent on purpose when no repository is supplied: the resolver must fall open on a capabilities
    // lookup it cannot make, and a test that stubs one would not prove that.
    if (null !== $capabilities) {
        $objectManager->shouldReceive('get')->with(CapabilitiesRepository::class)->andReturn($capabilities);
    }

    $defaultOptions = $defaultOptions ?? Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('hasOptionSet')->andReturn($defaultOptionSet)->byDefault();
    $defaultOptions->shouldReceive('hasDefaultOption')->andReturn($defaultOptionSet)->byDefault();
    $defaultOptions->shouldReceive('getDefaultInsurance')->andReturn(0)->byDefault();

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
