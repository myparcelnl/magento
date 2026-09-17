<?php

declare(strict_types=1);

use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Setup\Migrations\UnscopeWeightIndication;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;
use Psr\Log\LoggerInterface;

function unscopeWeightIndication(MyParcelTokenLifecycleHarness $harness, ?LoggerInterface $logger = null): UnscopeWeightIndication
{
    return new UnscopeWeightIndication(
        $harness->collectionFactory(),
        $harness->writer(),
        $logger ?? Mockery::spy(LoggerInterface::class)
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

    unscopeWeightIndication($harness)->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'kilo'],
    ]);
});

it('promotes the only scoped value when there is no default row at all', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_STORES, 4);

    unscopeWeightIndication($harness)->run();

    expect(weightIndicationRows($harness))->toBe([
        ['scope' => 'default', 'scope_id' => 0, 'value' => 'kilo'],
    ]);
});

it('keeps the default when the scopes disagree, because no automatic choice is defensible', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $harness->save(UnscopeWeightIndication::PATH, 'gram', 'default', 0);
    $harness->save(UnscopeWeightIndication::PATH, 'kilo', ScopeInterface::SCOPE_WEBSITES, 1);
    $harness->save(UnscopeWeightIndication::PATH, 'gram', ScopeInterface::SCOPE_STORES, 7);

    unscopeWeightIndication($harness)->run();

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

    unscopeWeightIndication($harness, $logger)->run();

    $logger->shouldHaveReceived('notice')->twice();
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
