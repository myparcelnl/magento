<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
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
    $order = createOrder([
        'getShippingAddress' => createAddress(['getCountryId' => $countryId]),
    ]);

    // Answers "not configured" for every setting, so resolve() reaches its own defaults rather
    // than a BadMethodCallException. An option that needs a real setting stubs it per test.
    $config = createConfig();

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn($config);

    // Absent on purpose when no repository is supplied: the resolver must fall open on a capabilities
    // lookup it cannot make, and a test that stubs one would not prove that.
    if (null !== $capabilities) {
        // A real ShapeLookup over the stubbed repository, so the request the resolver asks for is
        // the one the repository double asserts.
        $objectManager->shouldReceive('get')
            ->with(ShapeLookup::class)
            ->andReturn(new ShapeLookup($capabilities));
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
