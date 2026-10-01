<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Blueprint\Generator;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Service\Config;

/**
 * @param array[]  $items         contract definition items
 * @param string[] $international carriers whose international mailbox flag is on
 */
function generatedFields(array $items, array $international = []): array
{
    return fieldsByPath(Generator::for(CapabilitySet::fromContractDefinitionItems($items), $international)->toArray()['sections']);
}

function sectionIds(array $items): array
{
    return array_column(Generator::for(CapabilitySet::fromContractDefinitionItems($items))->toArray()['sections'], 'id');
}

const NO_TRACKING = ['noTracking' => ['isRequired' => false, 'isSelectedByDefault' => false]];

it('offers no_tracking as a bare toggle, for the carrier that reports it only', function () {
    $fields = generatedFields([
        contractDefinitionItem(['carrier' => 'POSTNL', 'options' => capabilityOptions(NO_TRACKING)]),
        contractDefinitionItem(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE']]),
    ]);

    $postnl = Config::carrierPath('postnl') . 'default_options/';
    $dpd    = Config::carrierPath('dpd') . 'default_options/';

    expect($fields[$postnl . 'no_tracking_active']['label'])->toBe("Automate 'No tracking'")
        ->and($fields[$postnl . 'no_tracking_active']['source_model'])->toBe(\Magento\Config\Model\Config\Source\Yesno::class)
        ->and($fields)->not->toHaveKeys([
            $postnl . 'no_tracking_from_price',
            Config::carrierPath('postnl') . 'delivery/no_tracking_active',
            Config::carrierPath('postnl') . 'delivery/no_tracking_fee',
            $dpd . 'no_tracking_active',
        ]);
});

it('offers no_tracking when only one package type reports it', function () {
    $fields = generatedFields([
        contractDefinitionItem(['carrier' => 'POSTNL', 'packageTypes' => ['PACKAGE']]),
        contractDefinitionItem(['carrier' => 'POSTNL', 'packageTypes' => ['MAILBOX'], 'options' => capabilityOptions(NO_TRACKING)]),
    ]);

    expect($fields)->toHaveKey(Config::carrierPath('postnl') . 'default_options/no_tracking_active');
});

it('gives a carrier the SDK does not know a section, with its switches disabled', function () {
    $fields = generatedFields([
        contractDefinitionItem(['carrier' => 'HOOPLA', 'deliveryTypes' => ['STANDARD_DELIVERY', 'PICKUP_DELIVERY']]),
    ]);

    $path = Config::carrierPath('hoopla');

    expect(sectionIds([contractDefinitionItem(['carrier' => 'HOOPLA'])]))->toContain('myparcelnl_magento_hoopla_settings')
        ->and($fields[$path . 'delivery/active']['disabled'])->toBeTrue()
        ->and($fields[$path . 'delivery/active']['comment'])->toContain('module update')
        ->and($fields[$path . 'pickup/active']['disabled'])->toBeTrue()
        ->and($fields[$path . 'default_options/insurance_row_amount'])->toBeArray()
        ->and($fields[$path . 'drop_off_days/day_1_active'])->not->toHaveKey('disabled');
});

it('leaves the switches of a carrier the SDK knows enabled, a form shape or not', function (string $v2Name, string $carrier) {
    $fields = generatedFields([contractDefinitionItem(['carrier' => $v2Name])]);

    expect($fields[Config::carrierPath($carrier) . 'delivery/active'])->not->toHaveKey('disabled');
})->with([
    'known'      => ['POSTNL', 'postnl'],
    'discovered' => ['UPS_EXPRESS_SAVER', 'upsexpresssaver'],
]);

it('takes the carriers from capabilities, in their order, and none when they could not be read', function () {
    expect(sectionIds([
        contractDefinitionItem(['carrier' => 'BPOST']),
        contractDefinitionItem(['carrier' => 'POSTNL']),
    ]))->toBe(['myparcelnl_magento_general', 'myparcelnl_magento_bpost_settings', 'myparcelnl_magento_postnl_settings']);
});

it('offers the international mailbox only for a carrier the account flag names', function () {
    $items = [
        contractDefinitionItem(['carrier' => 'POSTNL', 'packageTypes' => ['PACKAGE', 'MAILBOX']]),
        contractDefinitionItem(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE', 'MAILBOX']]),
    ];

    $fields = generatedFields($items, ['postnl']);

    expect($fields)->toHaveKey(Config::carrierPath('postnl') . 'mailbox/international_active')
        ->not->toHaveKey(Config::carrierPath('dpd') . 'mailbox/international_active')
        ->and(generatedFields($items))->not->toHaveKey(Config::carrierPath('postnl') . 'mailbox/international_active');
});

it('offers no delivery day that is not a capability', function () {
    $fields = generatedFields([contractDefinitionItem(['carrier' => 'POSTNL']), contractDefinitionItem(['carrier' => 'GLS'])]);

    expect($fields)->not->toHaveKeys([
        Config::carrierPath('postnl') . 'delivery/monday_active',
        Config::carrierPath('postnl') . 'delivery/monday_fee',
        Config::carrierPath('gls') . 'delivery/saturday_active',
        Config::carrierPath('gls') . 'delivery/saturday_fee',
        Config::XML_PATH_GENERAL . 'delivery_titles/monday_delivery_title',
    ]);
});

it('offers every insurance zone for every carrier', function () {
    $fields = generatedFields([contractDefinitionItem(['carrier' => 'UPS_STANDARD'])]);

    foreach (['local', 'belgium', 'eu', 'row'] as $zone) {
        expect($fields)->toHaveKey(Config::carrierPath('upsstandard') . "default_options/insurance_{$zone}_amount");
    }
});

it('builds groups from package types and delivery types', function () {
    $fields = generatedFields([
        contractDefinitionItem([
            'carrier'       => 'DHL_FOR_YOU',
            'packageTypes'  => ['PACKAGE'],
            'deliveryTypes' => ['STANDARD_DELIVERY', 'EVENING_DELIVERY'],
        ]),
    ]);

    $path = Config::carrierPath('dhlforyou');

    expect($fields)->toHaveKeys([$path . 'evening/active', $path . 'evening/fee', $path . 'drop_off_days/cutoff_time_0'])
        ->not->toHaveKeys([$path . 'mailbox/active', $path . 'pickup/active', $path . 'morning/active']);
});

it('offers only the delivery titles the account has a use for', function () {
    $fields = generatedFields([
        contractDefinitionItem([
            'carrier'       => 'DPD',
            'packageTypes'  => ['PACKAGE', 'MAILBOX'],
            'deliveryTypes' => ['STANDARD_DELIVERY', 'PICKUP_DELIVERY'],
        ]),
    ]);

    $titles = Config::XML_PATH_GENERAL . 'delivery_titles/';

    expect($fields)->toHaveKeys([
        $titles . 'delivery_title',
        $titles . 'mailbox_title',
        $titles . 'pickup_title',
        $titles . 'signature_title',
        $titles . 'pop_up_map_confirm_title',
    ])->not->toHaveKeys([
        $titles . 'morning_title',
        $titles . 'digital_stamp_title',
        $titles . 'hide_sender_title',
    ]);
});

it('offers every title but no carrier when permissive', function () {
    $blueprint = Generator::for(CapabilitySet::permissive());
    $titles    = array_filter($blueprint->paths(), static fn(string $p): bool => false !== strpos($p, '/delivery_titles/'));

    expect($blueprint->isPermissive())->toBeTrue()
        ->and($titles)->toHaveCount(23)
        ->and(array_column($blueprint->toArray()['sections'], 'id'))->toBe(['myparcelnl_magento_general']);
});

it('never offers the stored account settings', function () {
    $blueprints = [
        Generator::for(CapabilitySet::permissive()),
        Generator::for(CapabilitySet::fromContractDefinitionItems([contractDefinitionItem()])),
    ];

    foreach ($blueprints as $blueprint) {
        foreach ($blueprint->paths() as $path) {
            expect(strpos($path, Config::XML_PATH_ACCOUNT_SETTINGS))->not->toBe(0, $path);
        }
    }
});
