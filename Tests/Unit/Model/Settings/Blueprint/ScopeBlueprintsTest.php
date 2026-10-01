<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Blueprint\ScopeBlueprints;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Service\MailboxInternational;

/**
 * 'live-key' is configured at website 2 only, so the default scope has no key and reads permissive.
 *
 * @param string[] $contracted v2 carrier names in the stored row of 'live-key'; none reads permissive
 */
function scopeBlueprintsFor(array $contracted, MailboxInternational $mailbox): ScopeBlueprints
{
    $config   = createConfig([], [], [], ['websites' => [2 => [Config::XML_PATH_API_KEY => 'live-key']]]);
    $contract = new ContractDefinitions(mockScopeConfig(contractRowsFor($contracted)), new Fingerprint(), $config);

    return new ScopeBlueprints($contract, $config, $mailbox);
}

/** @param string[] $carriers the carriers the account flag names for 'live-key' */
function mailboxFlagging(array $carriers): MailboxInternational
{
    $mailbox = Mockery::mock(MailboxInternational::class);
    $mailbox->shouldReceive('carriersFor')->with('live-key')->andReturn($carriers)->byDefault();

    return $mailbox;
}

it('offers the international mailbox under the carrier the account flag names', function () {
    $paths = scopeBlueprintsFor(['POSTNL', 'DPD'], mailboxFlagging(['postnl']))->forScope('websites', 2)->paths();

    expect($paths)->toContain(Config::carrierPath('postnl') . 'mailbox/international_active')
        ->not->toContain(Config::carrierPath('dpd') . 'mailbox/international_active');
});

it('builds the form of a scope once', function () {
    $mailbox = Mockery::mock(MailboxInternational::class);
    $mailbox->shouldReceive('carriersFor')->once()->andReturn([]);

    $blueprints = scopeBlueprintsFor(['POSTNL'], $mailbox);

    expect($blueprints->forScope('websites', 2))->toBe($blueprints->forScope('websites', 2));
});

it('reads no account flag for a scope whose contract could not be read', function () {
    $mailbox = Mockery::mock(MailboxInternational::class);
    $mailbox->shouldNotReceive('carriersFor');

    $blueprint = scopeBlueprintsFor([], $mailbox)->forScope('websites', 2);

    expect($blueprint->isPermissive())->toBeTrue()
        ->and(array_column($blueprint->toArray()['sections'], 'id'))->toBe(['myparcelnl_magento_general']);
});

it('answers for the scope it was asked about', function () {
    $blueprints = scopeBlueprintsFor(['POSTNL'], mailboxFlagging([]));

    expect($blueprints->forScope('default', null)->isPermissive())->toBeTrue()
        ->and($blueprints->forScope('websites', 2)->isPermissive())->toBeFalse()
        ->and(array_column($blueprints->forScope('websites', 2)->toArray()['sections'], 'id'))
        ->toContain('myparcelnl_magento_postnl_settings');
});

it('leaves a default-only field out of a website scope, so a save there cannot write it', function () {
    $blueprints = scopeBlueprintsFor(['POSTNL'], mailboxFlagging([]));
    $weightType = Config::XML_PATH_GENERAL . 'print/weight_indication';

    expect($blueprints->forScope('default', null)->paths())->toContain($weightType)
        ->and($blueprints->forScope('websites', 2)->paths())->not->toContain($weightType);
});
