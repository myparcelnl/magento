<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
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

/** The real Checkout with only the collaborators getDeliveryData() reads; returns the settings of one carrier. */
function checkoutCarrierSettings(Config $config, Tax $tax, DeliveryCosts $deliveryCosts, Quote $quote, string $carrier = 'postnl'): array
{
    $package = Mockery::mock(PackageRepository::class)->shouldIgnoreMissing();
    $package->shouldReceive('selectPackageType')->andReturn(PackageType::PACKAGE_NAME);
    $package->shouldReceive('getAgeCheck')->andReturn(false);
    $package->shouldReceive('getPriorityDelivery')->andReturn(false);
    $package->deliveryOptionsDisabled = false;

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'tax', $tax);
    setPrivateProperty($checkout, 'deliveryCosts', $deliveryCosts);
    setPrivateProperty($checkout, 'package', $package);
    setPrivateProperty($checkout, 'quote', $quote);
    setPrivateProperty($checkout, 'capabilityLookup', capabilityLookupWith(CapabilitySet::permissive(), 'NL', 1));

    $data = invokePrivateMethod($checkout, 'getDeliveryData', [PackageType::PACKAGE_NAME, 'NL']);

    return $data['carrierSettings'][$carrier];
}
