<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;

it('reads the stored items into a capability set', function () {
    $reader = contractDefinitionsFor([
        settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem()]),
    ]);

    $set = $reader->forApiKey('live-key');

    expect($set->isPermissive())->toBeFalse()
        ->and($set->carriers())->toBe([Carrier::POSTNL]);
});

it('keeps the insurance bounds the settings screen needs', function () {
    $reader = contractDefinitionsFor([
        settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem()]),
    ]);

    $insurance = $reader->forApiKey('live-key')
                        ->optionValue(Carrier::POSTNL, null, ShipmentOption::INSURANCE);

    expect($insurance['min']['amount'])->toBe(0)
        ->and($insurance['max']['amount'])->toBe(500000)
        ->and($insurance['default']['amount'])->toBe(10000);
});

it('answers permissive when the account has no stored row', function () {
    expect(contractDefinitionsFor([])->forApiKey('live-key')->isPermissive())->toBeTrue();
});

it('answers permissive when the row predates contract definitions', function () {
    $reader = contractDefinitionsFor([
        settingsPathFor('live-key') => '{"shop":{"id":42},"account":{"id":7},"carrier_options":[]}',
    ]);

    expect($reader->forApiKey('live-key')->isPermissive())->toBeTrue();
});

it('answers permissive for a row whose list came back empty', function () {
    $reader = contractDefinitionsFor([settingsPathFor('live-key') => accountSettingsRow([])]);

    // An import that reached no carrier must not read as an account with no carriers, or the
    // delivery-costs form would hide every lane until the next import.
    expect($reader->forApiKey('live-key')->isPermissive())->toBeTrue();
});

it('answers permissive for an unparseable row rather than throwing', function () {
    $reader = contractDefinitionsFor([settingsPathFor('live-key') => 'not json at all']);

    expect($reader->forApiKey('live-key')->isPermissive())->toBeTrue();
});

it('answers permissive for a store with no api key', function () {
    expect(contractDefinitionsFor([])->forApiKey('')->isPermissive())->toBeTrue();
});

it('never serves one account the other account answer', function () {
    $reader = contractDefinitionsFor([
        settingsPathFor('key-a') => accountSettingsRow([contractDefinitionItem(['carrier' => 'POSTNL'])]),
        settingsPathFor('key-b') => accountSettingsRow([contractDefinitionItem(['carrier' => 'GLS'])]),
    ]);

    expect($reader->forApiKey('key-a')->carriers())->toBe([Carrier::POSTNL])
        ->and($reader->forApiKey('key-b')->carriers())->toBe([Carrier::GLS]);
});

it('resolves the api key configured at the asked scope', function () {
    $reader = contractDefinitionsFor(
        [settingsPathFor('store-key') => accountSettingsRow([contractDefinitionItem(['carrier' => 'GLS'])])],
        [ScopeInterface::SCOPE_STORES => [3 => [Config::XML_PATH_API_KEY => ' store-key ']]]
    );

    expect($reader->forScope(ScopeInterface::SCOPE_STORES, 3)->carriers())->toBe([Carrier::GLS])
        ->and($reader->forScope(ScopeInterface::SCOPE_STORES, 9)->isPermissive())->toBeTrue();
});

it('narrows nothing by package type, because a contract has no shipment', function () {
    $reader = contractDefinitionsFor([
        settingsPathFor('live-key') => accountSettingsRow([contractDefinitionItem()]),
    ]);

    $set = $reader->forApiKey('live-key');

    expect($set->hasOption(Carrier::POSTNL, PackageType::MAILBOX_NAME, ShipmentOption::INSURANCE))->toBeTrue();
});
