<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Blueprint\Generator;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

// The form for a contract shaped like the eight carriers of the deleted etc/dynamic_settings.json,
// against a byte copy of it. Every difference is listed here, so a change to the catalogue that moves
// a path or a msgid fails until it is named.

const LEGACY_ALLOWED_MISSING = [
    // Nothing reads it: the checkout and the carrier read {deliveryType}/fee.
    'myparcelnl_magento_gls_settings/delivery/delivery_fee',
    'myparcelnl_magento_trunkrs_settings/delivery/delivery_fee',
    // Not a capability, so not offered.
    'myparcelnl_magento_postnl_settings/delivery/monday_active',
    'myparcelnl_magento_postnl_settings/delivery/monday_fee',
    'myparcelnl_magento_general/delivery_titles/monday_delivery_title',
    // Nothing read it. Saturday delivery is the `saturday_delivery` option now.
    'myparcelnl_magento_gls_settings/delivery/saturday_active',
    'myparcelnl_magento_gls_settings/delivery/saturday_fee',
];

// What the contract adds where the legacy form had a per-carrier exception.
const LEGACY_ALLOWED_EXTRA = [
    // Every carrier gets every insurance zone; the contract bound clamps the amount.
    'myparcelnl_magento_dhlforyou_settings/default_options/insurance_eu_amount',
    'myparcelnl_magento_dhlforyou_settings/default_options/insurance_row_amount',
    'myparcelnl_magento_dhlparcelconnect_settings/default_options/insurance_local_amount',
    'myparcelnl_magento_dhlparcelconnect_settings/default_options/insurance_belgium_amount',
    'myparcelnl_magento_upsstandard_settings/default_options/insurance_belgium_amount',
    'myparcelnl_magento_upsstandard_settings/default_options/insurance_eu_amount',
    'myparcelnl_magento_upsstandard_settings/default_options/insurance_row_amount',
    // GLS offered signature and only recipient at checkout but did not automate them.
    'myparcelnl_magento_gls_settings/default_options/signature_active',
    'myparcelnl_magento_gls_settings/default_options/signature_from_price',
    'myparcelnl_magento_gls_settings/default_options/only_recipient_active',
    'myparcelnl_magento_gls_settings/default_options/only_recipient_from_price',
];

/** Each legacy carrier as a contract would report it: package types, delivery types, options. */
const LEGACY_CONTRACT = [
    'POSTNL'             => [
        ['package', 'digital_stamp', 'mailbox', 'package_small'],
        ['standard', 'morning', 'evening', 'pickup'],
        ['signature', 'receipt_code', 'only_recipient', 'return', 'large_format', 'age_check', 'insurance', 'priority_delivery'],
    ],
    'DHL_FOR_YOU'        => [['package', 'mailbox'], ['standard', 'pickup', 'same_day'], ['signature', 'only_recipient', 'age_check', 'hide_sender', 'insurance']],
    'DHL_EUROPLUS'       => [['package'], ['standard'], ['insurance']],
    'DHL_PARCEL_CONNECT' => [['package'], ['standard', 'pickup'], ['insurance']],
    'UPS_STANDARD'       => [['package'], ['standard'], ['signature', 'collect', 'only_recipient', 'age_check', 'insurance']],
    'DPD'                => [['package', 'mailbox'], ['standard', 'pickup'], []],
    'GLS'                => [['package'], ['standard', 'pickup'], ['signature', 'only_recipient', 'insurance']],
    'TRUNKRS'            => [['package'], ['standard'], ['signature', 'only_recipient', 'age_check', 'receipt_code', 'fresh_food', 'frozen']],
];

// One fee template: the other six carriers' fees had no validate, and parseDecimal() reads any notation.
const LEGACY_ALLOWED_VALIDATE = [
    'myparcelnl_magento_gls_settings/delivery/signature_fee',
    'myparcelnl_magento_gls_settings/delivery/only_recipient_fee',
    'myparcelnl_magento_trunkrs_settings/delivery/signature_fee',
    'myparcelnl_magento_trunkrs_settings/delivery/only_recipient_fee',
    'myparcelnl_magento_trunkrs_settings/delivery/receipt_code_fee',
];

// One label template: Trunkrs had its own wording, GLS one "(Local)" suffix.
const LEGACY_ALLOWED_LABELS = [
    'myparcelnl_magento_gls_settings/default_options/insurance_local_amount'        => 'Insure orders up to',
    'myparcelnl_magento_trunkrs_settings/default_options/signature_active'          => "Automate 'Signature on receipt'",
    'myparcelnl_magento_trunkrs_settings/default_options/signature_from_price'      => 'From price',
    'myparcelnl_magento_trunkrs_settings/default_options/only_recipient_active'     => "Automate 'Only recipient'",
    'myparcelnl_magento_trunkrs_settings/default_options/only_recipient_from_price' => 'From price',
    'myparcelnl_magento_trunkrs_settings/default_options/age_check_active'          => "Automate 'Age check 18+'",
    'myparcelnl_magento_trunkrs_settings/default_options/receipt_code_active'       => "Automate 'Receipt code'",
    'myparcelnl_magento_trunkrs_settings/default_options/receipt_code_from_price'   => 'From price',
    'myparcelnl_magento_trunkrs_settings/default_options/fresh_food_active'         => "Automate 'Fresh food'",
    'myparcelnl_magento_trunkrs_settings/default_options/fresh_food_from_price'     => 'From price',
    'myparcelnl_magento_trunkrs_settings/default_options/frozen_active'             => "Automate 'Frozen'",
    'myparcelnl_magento_trunkrs_settings/default_options/frozen_from_price'         => 'From price',
];

/** @return array<string, array> every field of a sections array, keyed by path */
function fieldsByPath(array $sections): array
{
    $fields = [];

    foreach ($sections as $section) {
        foreach ($section['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['path']] = $field;
            }
        }
    }

    return $fields;
}

function legacySections(): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/../../../../Fixtures/dynamic-settings-legacy.json'), true)['sections'];
}

function legacyShapedSections(): array
{
    $items = [];

    foreach (LEGACY_CONTRACT as $v2Carrier => [$packageTypes, $deliveryTypes, $options]) {
        $items[] = contractDefinitionItem([
            'carrier'       => $v2Carrier,
            'packageTypes'  => array_map([PackageType::class, 'toV2Name'], $packageTypes),
            'deliveryTypes' => array_map([DeliveryType::class, 'toV2Name'], $deliveryTypes),
            'options'       => array_fill_keys(array_map([ShipmentOption::class, 'toV2Name'], $options), []),
        ]);
    }

    return Generator::for(CapabilitySet::fromContractDefinitionItems($items), ['postnl'])->toArray()['sections'];
}

/** The attributes that decide what a field stores and when it shows. Depends order carries no meaning. */
function storageShape(array $field): array
{
    $depends = $field['depends'] ?? [];
    usort($depends, static fn(array $a, array $b): int => strcmp($a['field'], $b['field']));

    return [
        'type'           => $field['type'],
        'source_model'   => ltrim($field['source_model'] ?? '', '\\'),
        'frontend_model' => ltrim($field['frontend_model'] ?? '', '\\'),
        'note_model'     => $field['note_model'] ?? '',
        'depends'        => $depends,
        'scopes'         => [$field['showInDefault'], $field['showInWebsite'], $field['showInStore']],
    ];
}

it('offers every path the legacy form had, and adds only the listed ones and drop-off days', function () {
    $legacy    = array_keys(fieldsByPath(legacySections()));
    $generated = array_keys(fieldsByPath(legacyShapedSections()));

    $extra = array_values(array_diff($generated, $legacy, LEGACY_ALLOWED_EXTRA));

    expect(array_values(array_diff($legacy, $generated)))->toEqualCanonicalizing(LEGACY_ALLOWED_MISSING)
        ->and(array_values(array_diff(LEGACY_ALLOWED_EXTRA, $generated)))->toBe([])
        ->and($extra)->toHaveCount(28);

    foreach ($extra as $path) {
        expect($path)->toMatch('#^myparcelnl_magento_(gls|trunkrs)_settings/drop_off_days/#');
    }
});

it('stores and shows every legacy path the same way', function () {
    $generated = fieldsByPath(legacyShapedSections());

    foreach (fieldsByPath(legacySections()) as $path => $field) {
        if (in_array($path, LEGACY_ALLOWED_MISSING, true)) {
            continue;
        }

        expect(storageShape($generated[$path]))->toBe(storageShape($field), $path);

        if (! in_array($path, LEGACY_ALLOWED_VALIDATE, true)) {
            expect($generated[$path]['validate'] ?? null)->toBe($field['validate'] ?? null, $path);
        }
    }
});

it('keeps every legacy label, so no locale loses a translation', function () {
    $generated = fieldsByPath(legacyShapedSections());

    foreach (fieldsByPath(legacySections()) as $path => $field) {
        if (isset($generated[$path])) {
            expect($generated[$path]['label'])->toBe(LEGACY_ALLOWED_LABELS[$path] ?? $field['label'], $path);
        }
    }
});

it('keeps the general section byte for byte, tooltips and comments included', function () {
    $legacy    = legacySections()[0];
    $generated = legacyShapedSections()[0];

    expect($generated['id'])->toBe($legacy['id'])
        ->and(array_column($generated['groups'], 'id'))->toBe(array_column($legacy['groups'], 'id'))
        ->and(array_column($generated['groups'], 'comment'))->toBe(array_column($legacy['groups'], 'comment'));

    $generatedFields = fieldsByPath([$generated]);

    foreach (fieldsByPath([$legacy]) as $path => $field) {
        if (in_array($path, LEGACY_ALLOWED_MISSING, true)) {
            continue;
        }

        expect([$generatedFields[$path]['tooltip'] ?? null, $generatedFields[$path]['comment'] ?? null])
            ->toBe([$field['tooltip'] ?? null, $field['comment'] ?? null], $path);
    }
});

it('adds no fee field the legacy form did not have', function () {
    $legacy = fieldsByPath(legacySections());

    foreach (array_keys(fieldsByPath(legacyShapedSections())) as $path) {
        if (preg_match('#_fee$|/fee$#', $path)) {
            expect($legacy)->toHaveKey($path);
        }
    }
});
