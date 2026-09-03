<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

/**
 * The config path an api key's account settings belong at. Uses the real Fingerprint, so a test
 * expressing an expectation cannot drift from the algorithm production code uses.
 */
function settingsPathFor(string $apiKey): string
{
    return Config::XML_PATH_ACCOUNT_SETTINGS . (new Fingerprint())->of($apiKey);
}

/**
 * The pre-fingerprint path, where the plaintext api key sat in the path itself. Only the rewrite
 * patch may still encounter one.
 */
function legacySettingsPathFor(string $apiKey): string
{
    return Config::XML_PATH_ACCOUNT_SETTINGS . $apiKey;
}

/**
 * A ContractDefinitions reading the given stored rows, with the api key resolved from $scopedValues.
 *
 * @param array<string, string> $rowsByPath
 */
function contractDefinitionsFor(array $rowsByPath, array $scopedValues = []): ContractDefinitions
{
    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturnUsing(
        static fn (string $path) => $rowsByPath[$path] ?? null
    );

    return new ContractDefinitions($scopeConfig, new Fingerprint(), createConfig([], [], [], $scopedValues));
}

/**
 * The stored account settings row for 'live-key', carrying one PostNL contract whose insurance
 * option has the given bounds in cents.
 *
 * @return array<string, string>
 */
function contractRow(int $maxCents = 500000, int $minCents = 0, bool $required = false): array
{
    return [settingsPathFor('live-key') => accountSettingsRow([
        contractDefinitionItem([
            'options' => capabilityOptions(['insurance' => [
                'isRequired' => $required,
                'min'        => ['amount' => $minCents],
                'max'        => ['amount' => $maxCents],
            ]]),
        ]),
    ])];
}
