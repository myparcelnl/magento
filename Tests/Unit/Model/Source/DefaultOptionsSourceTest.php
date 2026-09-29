<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\OptionSource;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;

/**
 * Which tier switched an option on, which ShipmentOptionsResolver reads to settle an excludes
 * conflict. mockAttributeValueLookup() lives in Tests/Helpers/ShipmentBuilderMocks.php.
 */
function optionSourceFor(string $option, array $settings, array $chosen = [], string $productAgeCheck = ''): ?int
{
    $config = createConfig([], [Carrier::POSTNL => ['default_options' => $settings]]);

    mockAttributeValueLookup($productAgeCheck, [Config::class => $config]);

    $order = createOrder([
        'getItems'        => [createOrderItem(['product_id' => '7'])],
        'deliveryOptions' => json_encode(['carrier' => Carrier::POSTNL, 'deliveryType' => 'standard', 'shipmentOptions' => $chosen]),
    ]);

    return (new DefaultOptions($order))->sourceOf($option, Carrier::POSTNL);
}

it('names the configuration when only the carrier setting forces the option', function () {
    expect(optionSourceFor(ShipmentOption::SIGNATURE, ['signature_active' => '1']))->toBe(OptionSource::CONFIGURATION);
});

it('names the checkout when the customer chose the option', function () {
    expect(optionSourceFor(ShipmentOption::SIGNATURE, [], [ShipmentOption::SIGNATURE => true]))
        ->toBe(OptionSource::CHECKOUT);
});

it('names the product for an 18+ order, even when the checkout chose the age check too', function () {
    expect(optionSourceFor(ShipmentOption::AGE_CHECK, [], [ShipmentOption::AGE_CHECK => true], '1'))
        ->toBe(OptionSource::PRODUCT);
});

it('names nothing for an option that is off', function () {
    expect(optionSourceFor(ShipmentOption::SIGNATURE, ['signature_active' => '0']))->toBeNull()
        ->and(optionSourceFor(ShipmentOption::AGE_CHECK, ['age_check_active' => '1'], [], '0'))->toBeNull();
});
