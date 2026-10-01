<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\InternationalMailbox;
use MyParcelNL\Sdk\Model\Account\GeneralSettings;

it('reads the carrier from the flag the API sends today', function () {
    // The SDK's own key, so a rename in the API and the SDK fails here rather than hiding the field.
    $settings = (new GeneralSettings(['postnl_mailbox_international' => true]))->toArray();

    expect(InternationalMailbox::carriersIn($settings))->toBe(['postnl']);
});

it('derives a carrier with underscores in its name the way module names derive', function () {
    expect(InternationalMailbox::carriersIn(['dhl_for_you_mailbox_international' => true]))->toBe(['dhlforyou']);
});

it('reads only flags that are on', function () {
    expect(InternationalMailbox::carriersIn([
        'postnl_mailbox_international' => false,
        'dpd_mailbox_international'    => true,
    ]))->toBe(['dpd']);
});

it('ignores a flag whose prefix is no carrier the SDK knows', function () {
    expect(InternationalMailbox::carriersIn([
        'hoopla_mailbox_international' => true,
        '_mailbox_international'       => true,
    ]))->toBe([]);
});

it('ignores every other general setting', function () {
    expect(InternationalMailbox::carriersIn([
        'order_mode'                       => true,
        'postnl_mailbox_international_old' => true,
        'has_carrier_contract'             => true,
    ]))->toBe([]);
});
