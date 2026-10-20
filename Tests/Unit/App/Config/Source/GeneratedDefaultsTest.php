<?php

declare(strict_types=1);

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Scope\Converter;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\App\Config\Source\GeneratedDefaults;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;
use Psr\Log\LoggerInterface;

function generatedDefaults(
    MyParcelTokenLifecycleHarness $harness,
    bool $dbAvailable = true,
    ?LoggerInterface $logger = null,
    ?CollectionFactory $collectionFactory = null
): GeneratedDefaults {
    $deploymentConfig = Mockery::mock(DeploymentConfig::class);
    $deploymentConfig->shouldReceive('isDbAvailable')->andReturn($dbAvailable);

    return new GeneratedDefaults(
        $deploymentConfig,
        $collectionFactory ?? $harness->collectionFactory(),
        new Converter(),
        new Fingerprint(),
        $logger ?? Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing()
    );
}

/** Stores an api key at a scope and its account's contract for these carriers at default scope. */
function storeAccount(MyParcelTokenLifecycleHarness $harness, string $apiKey, array $v2Carriers, string $scope = 'default', int $scopeId = 0): void
{
    $harness->save(Config::XML_PATH_API_KEY, $apiKey, $scope, $scopeId);

    foreach (contractRowsFor($v2Carriers, $apiKey) as $path => $row) {
        $harness->save($path, $row, 'default', 0);
    }
}

it('answers the general defaults without an account', function () {
    $source = generatedDefaults(new MyParcelTokenLifecycleHarness());

    expect($source->get('default/myparcelnl_magento_general/print/paper_type'))->toBe('A4')
        ->and($source->get('default/myparcelnl_magento_general/date_settings/deliverydays_window'))->toBe('7')
        ->and($source->get('default/myparcelnl_magento_postnl_settings'))->toBeNull();
});

it('answers the general defaults while the database is not available', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    storeAccount($harness, 'key-a', ['POSTNL']);

    $source = generatedDefaults($harness, false);

    expect($source->get('default/myparcelnl_magento_general/print/paper_type'))->toBe('A4')
        ->and($source->get('default/myparcelnl_magento_postnl_settings'))->toBeNull();
});

it('switches every carrier of every account on the install off', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    storeAccount($harness, 'key-a', ['POSTNL']);
    storeAccount($harness, 'key-b', ['DPD'], ScopeInterface::SCOPE_WEBSITES, 2);

    $source = generatedDefaults($harness);

    expect($source->get('default/myparcelnl_magento_postnl_settings/delivery/active'))->toBe('0')
        ->and($source->get('default/myparcelnl_magento_dpd_settings/delivery/active'))->toBe('0')
        ->and($source->get('default/myparcelnl_magento_gls_settings'))->toBeNull();
});

it('answers the default scope only, so a saved website or store row always wins', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    storeAccount($harness, 'key-a', ['POSTNL'], ScopeInterface::SCOPE_STORES, 3);

    expect(array_keys(generatedDefaults($harness)->get()))->toBe(['default']);
});

it('skips an account row it cannot read', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(Config::XML_PATH_API_KEY, 'key-a', 'default', 0);
    $harness->save(settingsPathFor('key-a'), '{not json', 'default', 0);

    $source = generatedDefaults($harness);

    expect($source->get('default/myparcelnl_magento_general/print/paper_type'))->toBe('A4')
        ->and($source->get('default/myparcelnl_magento_postnl_settings'))->toBeNull();
});

it('keeps the general defaults and logs when the rows cannot be read', function () {
    $factory = Mockery::mock(CollectionFactory::class);
    $factory->shouldReceive('create')->andThrow(new RuntimeException('table gone'));
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once()->with(Mockery::pattern('/table gone/'));

    $source = generatedDefaults(new MyParcelTokenLifecycleHarness(), true, $logger, $factory);

    expect($source->get('default/myparcelnl_magento_general/print/paper_type'))->toBe('A4');
});

it('takes nothing that reads config, which would recurse into the source', function () {
    $types = array_map(static function (ReflectionParameter $parameter): string {
        return (string) $parameter->getType();
    }, (new ReflectionMethod(GeneratedDefaults::class, '__construct'))->getParameters());

    expect($types)->not->toContain(ScopeConfigInterface::class)
        ->and($types)->not->toContain(Config::class);
});
