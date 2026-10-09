<?php

declare(strict_types=1);

use MyParcelNL\Magento\Setup\Migrations\LegacyConfigDefaults;

// The generated defaults for the legacy carriers, against what etc/config.xml supplied. Every
// difference is listed here. An existing install keeps the legacy value through LegacyConfigDefaults;
// these are what a new install starts with.

const DEFAULTS_ALLOWED_CHANGED = [
    // A new install starts with every carrier, delivery type and checkout option off.
    'myparcelnl_magento_postnl_settings/delivery/active' => '0',
    'myparcelnl_magento_postnl_settings/delivery/signature_active' => '0',
    'myparcelnl_magento_postnl_settings/delivery/only_recipient_active' => '0',
    'myparcelnl_magento_postnl_settings/morning/active' => '0',
    'myparcelnl_magento_postnl_settings/evening/active' => '0',
    'myparcelnl_magento_postnl_settings/pickup/active' => '0',
    'myparcelnl_magento_dhlforyou_settings/delivery/active' => '0',
    'myparcelnl_magento_dhlforyou_settings/delivery/signature_active' => '0',
    'myparcelnl_magento_dhlforyou_settings/delivery/only_recipient_active' => '0',
    'myparcelnl_magento_dhlforyou_settings/pickup/active' => '0',
    'myparcelnl_magento_dhleuroplus_settings/delivery/active' => '0',
    'myparcelnl_magento_dhlparcelconnect_settings/delivery/active' => '0',
    'myparcelnl_magento_dhlparcelconnect_settings/pickup/active' => '0',
    'myparcelnl_magento_upsstandard_settings/delivery/active' => '0',
    'myparcelnl_magento_dpd_settings/delivery/active' => '0',
    'myparcelnl_magento_dpd_settings/pickup/active' => '0',
    'myparcelnl_magento_gls_settings/delivery/active' => '0',
    'myparcelnl_magento_gls_settings/delivery/signature_active' => '0',
    'myparcelnl_magento_gls_settings/delivery/only_recipient_active' => '0',
    'myparcelnl_magento_gls_settings/pickup/active' => '0',
    'myparcelnl_magento_trunkrs_settings/delivery/active' => '0',
    'myparcelnl_magento_trunkrs_settings/delivery/signature_active' => '0',
    'myparcelnl_magento_trunkrs_settings/delivery/only_recipient_active' => '0',
    'myparcelnl_magento_trunkrs_settings/delivery/receipt_code_active' => '0',
    // A surcharge is the merchant's decision.
    'myparcelnl_magento_postnl_settings/delivery/signature_fee' => '0',
    'myparcelnl_magento_postnl_settings/delivery/only_recipient_fee' => '0',
    'myparcelnl_magento_postnl_settings/morning/fee' => '0',
    'myparcelnl_magento_postnl_settings/evening/fee' => '0',
    'myparcelnl_magento_dhlforyou_settings/delivery/signature_fee' => '0',
    'myparcelnl_magento_dhlforyou_settings/delivery/only_recipient_fee' => '0',
    'myparcelnl_magento_gls_settings/delivery/signature_fee' => '0',
    'myparcelnl_magento_gls_settings/delivery/only_recipient_fee' => '0',
    'myparcelnl_magento_trunkrs_settings/delivery/signature_fee' => '0',
    'myparcelnl_magento_trunkrs_settings/delivery/only_recipient_fee' => '0',
    // Every carrier gets the same insurance template; GLS no longer insures 100 by default.
    'myparcelnl_magento_gls_settings/default_options/insurance_local_amount' => '0',
    'myparcelnl_magento_gls_settings/default_options/insurance_eu_amount' => '0',
    'myparcelnl_magento_gls_settings/default_options/insurance_row_amount' => '0',
    // An empty title falls back to the translated one in Checkout, where the legacy default was Dutch.
    'myparcelnl_magento_general/delivery_titles/delivery_title' => null,
    'myparcelnl_magento_general/delivery_titles/standard_delivery_title' => null,
    'myparcelnl_magento_general/delivery_titles/signature_title' => null,
    'myparcelnl_magento_general/delivery_titles/only_recipient_title' => null,
    'myparcelnl_magento_general/delivery_titles/morning_title' => null,
    'myparcelnl_magento_general/delivery_titles/evening_title' => null,
    'myparcelnl_magento_general/delivery_titles/mailbox_title' => null,
    'myparcelnl_magento_general/delivery_titles/digital_stamp_title' => null,
    'myparcelnl_magento_general/delivery_titles/pickup_title' => null,
    'myparcelnl_magento_general/delivery_titles/pickup_list_button_title' => null,
    'myparcelnl_magento_general/delivery_titles/pickup_map_button_title' => null,
];

it('generates the legacy default for every legacy path, except the listed ones', function () {
    $generated = legacyShapedBlueprint()->defaults();
    $changed   = [];

    foreach (LegacyConfigDefaults::VALUES as $path => $legacy) {
        $default = $generated[$path] ?? null;

        if ($default !== $legacy) {
            $changed[$path] = $default;
        }
    }

    $allowed = DEFAULTS_ALLOWED_CHANGED;
    ksort($changed);
    ksort($allowed);

    expect($changed)->toBe($allowed);
});
