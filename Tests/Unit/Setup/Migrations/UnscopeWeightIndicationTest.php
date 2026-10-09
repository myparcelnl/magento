<?php

declare(strict_types=1);

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Setup\Migrations\UnscopeWeightIndication;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;
use Psr\Log\LoggerInterface;

/** @param array<int, int> $stores the install's store views, store id => website id */
function unscopeWeightIndication(
    MyParcelTokenLifecycleHarness $harness,
    ?LoggerInterface $logger = null,
    array $stores = [1 => 1]
): UnscopeWeightIndication {
    $storeViews = [];

    foreach ($stores as $storeId => $websiteId) {
        $store = Mockery::mock(StoreInterface::class);
        $store->shouldReceive('getId')->andReturn($storeId);
        $store->shouldReceive('getWebsiteId')->andReturn($websiteId);
        $storeViews[$storeId] = $store;
    }

    $storeManager = Mockery::mock(StoreManagerInterface::class);
    $storeManager->shouldReceive('getStores')->andReturn($storeViews);

    return new UnscopeWeightIndication(
        $harness->collectionFactory(),
        $harness->writer(),
        $logger ?? Mockery::spy(LoggerInterface::class),
        $storeManager
    );
}

/** @return array<int, array{scope: string, scope_id: int, value: string}> */
function weightIndicationRows(MyParcelTokenLifecycleHarness $harness): array
{
    $rows = [];

    foreach ($harness->rows as $row) {
        if (UnscopeWeightIndication::PATH === $row['path']) {
            $rows[] = ['scope' => $row['scope'], 'scope_id' => $row['scope_id'], 'value' => $row['value']];
        }
    }

    return $rows;
}

it('promotes a value every scope agrees on, so a merchant who only configured websites keeps it', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'gram', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 1);
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 2);

    unscopeWeightIndication($harness, null, [1 => 1, 2 => 2])->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'kilo'],
    ]);
});

it('promotes the value of the only store view when there is no default row at all', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_STORES, 4);

    unscopeWeightIndication($harness, null, [4 => 1])->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'kilo'],
    ]);
});

it('keeps the default when the scopes disagree, because no automatic choice is defensible', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'gram', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 1);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', ScopeInterface::SCOPE_STORES, 7);

    unscopeWeightIndication($harness, null, [1 => 1, 7 => 1])->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'gram'],
    ]);
});

it('names every row it removed when the scopes disagree, so support can reconstruct it', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $logger  = Mockery::spy(LoggerInterface::class);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 1);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', ScopeInterface::SCOPE_STORES, 7);

    unscopeWeightIndication($harness, $logger, [1 => 1, 7 => 1])->run();

    $logger->shouldHaveReceived('notice')->twice();
});

it('keeps the default when another website inherits it, so that website keeps its unit', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $logger  = Mockery::spy(LoggerInterface::class);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 1);

    unscopeWeightIndication($harness, $logger, [1 => 1, 2 => 2])->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'gram'],
    ]);
    $logger->shouldHaveReceived('notice')->once();
});

it('promotes nothing when another store view inherits the absent default', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_STORES, 4);

    unscopeWeightIndication($harness, null, [4 => 1, 5 => 1])->run();

    expect(weightIndicationRows($harness))->toBe([]);
});

it('ignores a row of a website that no longer exists, and still removes it', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'gram', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 9);

    unscopeWeightIndication($harness)->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'gram'],
    ]);
});

it('changes no value when the scopes only repeat the default', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $logger  = Mockery::spy(LoggerInterface::class);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', ScopeInterface::SCOPE_WEBSITES, 1);

    unscopeWeightIndication($harness, $logger)->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'gram'],
    ]);
    $logger->shouldNotHaveReceived('notice');
});

it('leaves a lone default row alone and stays a no-op on a second run', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', ScopeInterface::SCOPE_WEBSITES, 1);

    unscopeWeightIndication($harness)->run();
    $afterFirst = weightIndicationRows($harness);
    unscopeWeightIndication($harness)->run();

    expect(weightIndicationRows($harness))->toBe($afterFirst)
        ->and($afterFirst)->toHaveCount(1);
});

it('touches no other setting', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 1);
    $harness->save('myparcelnl_magento_general/print/paper_type', 'A4', ScopeInterface::SCOPE_WEBSITES, 1);

    unscopeWeightIndication($harness)->run();

    expect($harness->rowAt('myparcelnl_magento_general/print/paper_type'))->not->toBeNull();
});
