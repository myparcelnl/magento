<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\Tax;

/**
 * A Config mock for the checkout prices: only $carrier is active, with every delivery type and option on.
 *
 * @param array<string, float>  $floatConfig  fees by key, such as 'pickup/fee'
 * @param array<string, string> $configValues raw values by a part of the full path
 */
function checkoutPriceConfig(bool $totalView, array $floatConfig = [], array $configValues = [], string $carrier = 'postnl'): Config
{
    $carrierPath = Config::CARRIERS_XML_PATH_MAP[$carrier];

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getBoolConfig')->andReturnUsing(static fn ($path) => $carrierPath === $path)->byDefault();
    $config->shouldReceive('getFloatConfig')->andReturnUsing(static fn ($path, $key) => $floatConfig[$key] ?? 0.0)->byDefault();
    $config->shouldReceive('getTimeConfig')->andReturn('')->byDefault();
    $config->shouldReceive('getDefaultCarrierName')->andReturn($carrier)->byDefault();
    $config->shouldReceive('getConfigValue')->andReturnUsing(static function ($path) use ($totalView, $configValues) {
        if (false !== strpos((string) $path, 'delivery_options_prices')) {
            return $totalView ? PriceDeliveryOptionsView::TOTAL : PriceDeliveryOptionsView::SURCHARGE;
        }

        foreach ($configValues as $part => $value) {
            if (false !== strpos((string) $path, $part)) {
                return $value;
            }
        }

        return null;
    })->byDefault();

    return $config;
}

/**
 * A PackageTypeResolver that resolves package, offers every package type the capabilities allow
 * and finds every cart light enough for a mailbox. Override a method for one test.
 *
 * @return PackageTypeResolver|Mockery\MockInterface
 */
function packageTypeResolverMock(): PackageTypeResolver
{
    $resolver = Mockery::mock(PackageTypeResolver::class);
    $resolver->shouldReceive('resolve')->andReturn(PackageType::PACKAGE_NAME)->byDefault();
    $resolver->shouldReceive('candidates')->andReturnUsing(static function (array $allowed): PackageTypeCandidates {
        $candidates = PackageTypeCandidates::none();

        foreach (array_keys(array_filter($allowed)) as $packageTypeName) {
            $candidates = $candidates->with($packageTypeName);
        }

        return $candidates;
    })->byDefault();
    $resolver->shouldReceive('fitsMailboxWeight')->andReturn(true)->byDefault();

    return $resolver;
}

/** The real Checkout with only the collaborators getDeliveryData() reads; returns the settings of one carrier. */
function checkoutCarrierSettings(Config $config, Tax $tax, DeliveryCosts $deliveryCosts, Quote $quote, string $carrier = 'postnl'): array
{
    $cartRules = Mockery::mock(CartShippingRules::class);
    foreach (['forcedLimitingOptions' => [], 'hidesDeliveryOptions' => false, 'allowsPriorityDelivery' => false,
              'forcesAgeCheck' => false, 'dropOffDelay' => null] as $method => $answer) {
        $cartRules->shouldReceive($method)->andReturn($answer)->byDefault();
    }

    $checkout = checkoutWith([
        'config'              => $config,
        'tax'                 => $tax,
        'deliveryCosts'       => $deliveryCosts,
        'packageTypeResolver' => packageTypeResolverMock(),
        'cartRules'           => $cartRules,
        'quote'               => $quote,
        'storeId'             => 1,
        'capabilityLookup'    => capabilityLookupWith(CapabilitySet::permissive(), 'NL'),
    ]);

    return carrierSettingsOf($checkout, PackageType::PACKAGE_NAME, $carrier);
}

/** Make a Checkout without its constructor, which reads the checkout session, and set these private properties. */
function checkoutWith(array $properties): Checkout
{
    return instanceWithProperties(Checkout::class, $properties);
}

/** Get the carrier settings that the private getDeliveryData() gives the widget for one carrier. */
function carrierSettingsOf(Checkout $checkout, string $packageType, string $carrier): array
{
    return invokePrivateMethod($checkout, 'getDeliveryData', [$packageType, 'NL'])['carrierSettings'][$carrier];
}
