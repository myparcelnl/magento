<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;

/**
 * DefaultOptions over a quote double: the carrier's default_options, the grand total, the destination,
 * the account's home country and the stored delivery options JSON.
 *
 * @param array<string, mixed> $carrierSettings the carrier's default_options
 */
function defaultOptionsFor(
    array   $carrierSettings,
    float   $grandTotal,
    ?string $countryId = 'NL',
    string  $homeCountry = 'NL',
    ?string $storedDeliveryOptions = null
): DefaultOptions
{
    $config = createConfig([], ['postnl' => ['default_options' => $carrierSettings]]);
    mockLoggerFacade([Config::class => $config, StoredAccount::class => storedAccountAt($homeCountry)]);

    $address = null;

    if (null !== $countryId) {
        $address = Mockery::mock(Address::class);
        $address->shouldReceive('getCountryId')->andReturn($countryId);
    }

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getData')->andReturn($storedDeliveryOptions);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);
    $quote->shouldReceive('getGrandTotal')->andReturn($grandTotal);
    $quote->shouldReceive('getStoreId')->andReturn(1);

    return new DefaultOptions($quote);
}
