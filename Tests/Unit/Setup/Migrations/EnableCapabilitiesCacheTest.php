<?php

declare(strict_types=1);

use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Cache\State;
use Magento\Framework\App\DeploymentConfig;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities;
use MyParcelNL\Magento\Setup\Migrations\EnableCapabilitiesCache;

/**
 * Runs the migration against a stubbed env.php and records every setEnabled() call, so a test
 * asserts what was written rather than only that a mock expectation held.
 *
 * @param  array<string,int>|null $configuredTypes what env.php's cache_types holds
 * @return array{0: array, 1: callable} the recorded calls by reference, and the run
 */
function runEnableCapabilitiesCache(?array $configuredTypes, ?Throwable $writeFails = null): array
{
    $calls = [];

    $manager = Mockery::mock(Manager::class);
    $expectation = $manager->shouldReceive('setEnabled');

    if ($writeFails) {
        $expectation->andThrow($writeFails);
    } else {
        $expectation->andReturnUsing(static function (array $types, bool $enabled) use (&$calls): array {
            $calls[] = [$types, $enabled];

            return $types;
        });
    }

    $deploymentConfig = Mockery::mock(DeploymentConfig::class);
    $deploymentConfig->shouldReceive('get')->with(State::CACHE_KEY)->andReturn($configuredTypes);

    (new EnableCapabilitiesCache($manager, $deploymentConfig))->run();

    return $calls;
}

it('enables the type when env.php does not mention it', function () {
    expect(runEnableCapabilitiesCache(['config' => 1, 'full_page' => 1]))
        ->toBe([[[Capabilities::TYPE_IDENTIFIER], true]]);
});

it('treats a missing cache_types key as absent rather than throwing', function () {
    expect(runEnableCapabilitiesCache(null))
        ->toBe([[[Capabilities::TYPE_IDENTIFIER], true]]);
});

it('leaves a type the admin switched off alone', function () {
    expect(runEnableCapabilitiesCache(['config' => 1, Capabilities::TYPE_IDENTIFIER => 0]))->toBe([]);
});

it('changes nothing on a second run', function () {
    expect(runEnableCapabilitiesCache([Capabilities::TYPE_IDENTIFIER => 1]))->toBe([]);
});

it('survives a read-only app/etc instead of failing the upgrade', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/cache:enable myparcelnl_capabilities/'));

    expect(runEnableCapabilitiesCache([], new RuntimeException('read-only file system')))->toBe([]);
});
