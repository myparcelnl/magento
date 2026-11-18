<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Magento\Service\Config;

function insuranceRangeOfContracts(array $items): ?int
{
    $contracts = contractDefinitionsFor(
        [settingsPathFor('live-key') => accountSettingsRow($items)],
        [ScopeConfigInterface::SCOPE_TYPE_DEFAULT => [0 => [Config::XML_PATH_API_KEY => 'live-key']]]
    );

    $range = $contracts->insuranceRangeFor('postnl', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    return null === $range ? null : $range->max();
}

function contractWithInsuranceMax(array $packageTypes, int $maxCents): array
{
    return contractDefinitionItem([
        'packageTypes' => $packageTypes,
        'options'      => capabilityOptions(['insurance' => insuranceBounds(0, $maxCents)]),
    ]);
}

it('takes the insurance bound of a package, not of a contract listed before it', function () {
    expect(insuranceRangeOfContracts([
        contractWithInsuranceMax(['MAILBOX'], 10000),
        contractWithInsuranceMax(['PACKAGE'], 500000),
    ]))->toBe(5000);
});

it('takes the first bound of a carrier without a package contract', function () {
    expect(insuranceRangeOfContracts([contractWithInsuranceMax(['MAILBOX'], 10000)]))->toBe(100);
});
