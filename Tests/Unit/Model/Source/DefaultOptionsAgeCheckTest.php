<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;

/**
 * The age check default an order carries before the admin touches the form: what the products say,
 * then the carrier setting. The New Shipment page pre-checks its box from this answer, and the
 * export reads the same answer through ShipmentOptionsResolver, so the two cannot disagree.
 *
 * mockAttributeValueLookup() lives in Tests/Helpers/ShipmentBuilderMocks.php; every product in the
 * order answers the same attribute value.
 */
function ageCheckDefaultFor(string $productValue, bool $carrierDefault): bool
{
    $config = createConfig([], [
        Carrier::POSTNL => ['default_options' => ['age_check_active' => $carrierDefault ? '1' : '0']],
    ]);

    mockAttributeValueLookup($productValue, [Config::class => $config]);

    $order = createOrder(['getItems' => [createOrderItem(['product_id' => '7'])]]);

    return (new DefaultOptions($order))->hasOptionSet(ShipmentOption::AGE_CHECK, Carrier::POSTNL);
}

it('is on when a product is 18+, whatever the carrier setting says', function () {
    expect(ageCheckDefaultFor('1', false))->toBeTrue();
});

it('is off when the products explicitly say no, even with the carrier setting on', function () {
    expect(ageCheckDefaultFor('0', true))->toBeFalse();
});

it('falls through to the carrier setting when the products say nothing', function () {
    expect(ageCheckDefaultFor('', true))->toBeTrue();
});
