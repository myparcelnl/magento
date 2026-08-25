<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use Psr\Log\LoggerInterface;

/**
 * Only hasSettingsFor() is covered: the rest of Importer instantiates the SDK web services directly,
 * so it has no seam to test through.
 *
 * @param array<string, string> $rowsByPath
 */
function importerFor(array $rowsByPath): Importer
{
    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturnUsing(
        static fn (string $path) => $rowsByPath[$path] ?? null
    );

    return new Importer(
        Mockery::spy(WriterInterface::class),
        $scopeConfig,
        new Fingerprint(),
        Mockery::spy(LoggerInterface::class)
    );
}

function accountSettingsPath(string $apiKey): string
{
    return Config::XML_PATH_ACCOUNT_SETTINGS . (new Fingerprint())->of($apiKey);
}

it('reports settings present when a row exists for the key', function () {
    $importer = importerFor([accountSettingsPath('live-key') => '{"shop":1}']);

    expect($importer->hasSettingsFor('live-key'))->toBeTrue();
});

it('reports settings absent when no row exists at all', function () {
    expect(importerFor([])->hasSettingsFor('live-key'))->toBeFalse();
});

it('does not mistake another key\'s row for its own', function () {
    $importer = importerFor([accountSettingsPath('other-key') => '{"shop":9}']);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});

it('treats an empty stored value as absent', function () {
    $importer = importerFor([accountSettingsPath('live-key') => '']);

    expect($importer->hasSettingsFor('live-key'))->toBeFalse();
});
