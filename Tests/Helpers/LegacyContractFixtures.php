<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Blueprint\Blueprint;
use MyParcelNL\Magento\Model\Settings\Blueprint\Generator;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/** Each carrier of the deleted etc/dynamic_settings.json as a contract would report it: package types, delivery types, options. */
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

/** @return array[] LEGACY_CONTRACT as contract definition items */
function legacyContractItems(): array
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

    return $items;
}

/** The generated form for LEGACY_CONTRACT, with international mailbox on for PostNL. */
function legacyShapedBlueprint(): Blueprint
{
    return Generator::for(CapabilitySet::fromContractDefinitionItems(legacyContractItems()), ['postnl']);
}
