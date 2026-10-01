<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Capabilities\Client;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use Psr\Log\LoggerInterface;

/**
 * The config path an api key's account settings belong at. Uses the real Fingerprint, so a test
 * expressing an expectation cannot drift from the algorithm production code uses.
 */
function settingsPathFor(string $apiKey): string
{
    return Config::XML_PATH_ACCOUNT_SETTINGS . (new Fingerprint())->of($apiKey);
}

/**
 * The pre-fingerprint path, with the plaintext api key in the path itself. Only
 * FingerprintAccountSettingsPaths reads one.
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
    $scopeConfig = mockScopeConfig($rowsByPath);

    return new ContractDefinitions($scopeConfig, new Fingerprint(), createConfig([], [], [], $scopedValues));
}

/**
 * An Importer over the given stored rows. importFor() itself has no seam: it instantiates the SDK
 * account web service directly, so tests reach hasSettingsFor() and the contract-definitions halves.
 *
 * @param array<string, string> $rowsByPath
 */
function importerFor(array $rowsByPath, ?Client $client = null, ?LoggerInterface $logger = null): Importer
{
    return new Importer(
        Mockery::spy(WriterInterface::class),
        new Fingerprint(),
        $logger ?? Mockery::spy(LoggerInterface::class),
        $client ?? Mockery::spy(Client::class),
        createUserAgent(),
        contractDefinitionsFor($rowsByPath)
    );
}

/**
 * The stored row of $apiKey naming these carriers and nothing else about them, keyed by path. An
 * empty list is no row at all, which reads permissive: "could not find out", not "no carriers".
 *
 * @param  string[] $v2Names
 * @return array<string, string>
 */
function contractRowsFor(array $v2Names, string $apiKey = 'live-key'): array
{
    if ([] === $v2Names) {
        return [];
    }

    return [settingsPathFor($apiKey) => accountSettingsRow(array_map(static function (string $v2Name): array {
        return contractDefinitionItem(['carrier' => $v2Name]);
    }, $v2Names))];
}

/**
 * A ContractDefinitions whose stored contract for one store names these carriers.
 *
 * @param string[] $v2Names
 */
function storedContractFor(int $storeId, array $v2Names, string $apiKey = 'live-key'): ContractDefinitions
{
    return contractDefinitionsFor(
        contractRowsFor($v2Names, $apiKey),
        ['stores' => [$storeId => [Config::XML_PATH_API_KEY => $apiKey]]]
    );
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
            'options' => capabilityOptions(['insurance' => insuranceBounds($minCents, $maxCents, $required)]),
        ]),
    ])];
}

/**
 * Stubs the collaborators AccountSettings resolves from the static ObjectManager, and returns the
 * logger it alerts through. Pass a $reader that throws to stand for anything in the read failing.
 *
 * The ObjectManager instance is global, so call this inside the test that needs it.
 *
 * @param array<string, string> $rowsByPath
 */
function stubAccountSettingsReader(array $rowsByPath, ?callable $reader = null): LoggerInterface
{
    $scopeConfig = mockScopeConfig($rowsByPath, $reader);

    $logger = mockLoggerFacade([
        ScopeConfigInterface::class => $scopeConfig,
        Fingerprint::class          => new Fingerprint(),
    ]);
    $logger->shouldIgnoreMissing();

    return $logger;
}

/**
 * An AccountSettings over those stubs, with the logger beside it: a row it cannot use is an alert,
 * and that is half the behaviour.
 *
 * @param array<string, string> $rowsByPath
 *
 * @return array{settings: AccountSettings, logger: LoggerInterface}
 */
function accountSettingsFor(string $apiKey, array $rowsByPath, ?callable $reader = null): array
{
    $logger = stubAccountSettingsReader($rowsByPath, $reader);

    return ['settings' => new AccountSettings($apiKey), 'logger' => $logger];
}
