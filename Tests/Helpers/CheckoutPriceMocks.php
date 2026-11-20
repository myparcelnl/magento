<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

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

/** A Tax that passes every price through unchanged. */
function passThroughTax(): Tax
{
    $tax = Mockery::mock(Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturnUsing(static fn ($price) => $price);

    return $tax;
}

/** A quote in store 1 with the delivery options the customer chose, or none. */
function quoteWithDeliveryOptions(?string $savedDeliveryOptions = null): Quote
{
    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturn($savedDeliveryOptions)->byDefault();
    $quote->shouldReceive('getShippingAddress')->andReturnNull()->byDefault();
    $quote->shouldReceive('getStoreId')->andReturn(1)->byDefault();

    return $quote;
}

/** The real Checkout with only the collaborators getDeliveryData() reads. */
function checkoutForPricing(Config $config, Tax $tax, DeliveryCosts $deliveryCosts, Quote $quote): Checkout
{
    $package = Mockery::mock(PackageRepository::class)->shouldIgnoreMissing();
    $package->shouldReceive('selectPackageType')->andReturn(AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME);
    $package->shouldReceive('getAgeCheck')->andReturn(false);
    $package->shouldReceive('getPriorityDelivery')->andReturn(false);
    $package->deliveryOptionsDisabled = false;

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'tax', $tax);
    setPrivateProperty($checkout, 'deliveryCosts', $deliveryCosts);
    setPrivateProperty($checkout, 'package', $package);
    setPrivateProperty($checkout, 'quote', $quote);

    return $checkout;
}

/** Returns the settings of one carrier, as the real Checkout builds them. */
function checkoutCarrierSettings(Config $config, Tax $tax, DeliveryCosts $deliveryCosts, Quote $quote, string $carrier = 'postnl'): array
{
    $checkout = checkoutForPricing($config, $tax, $deliveryCosts, $quote);
    $data     = invokePrivateMethod($checkout, 'getDeliveryData', [AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME, 'NL']);

    return $data['carrierSettings'][$carrier];
}
