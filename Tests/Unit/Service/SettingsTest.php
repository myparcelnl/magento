<?php

declare(strict_types=1);

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\DataObject;
use MyParcelNL\Magento\Model\Settings\Blueprint\Blueprint;
use MyParcelNL\Magento\Model\Settings\Blueprint\Field;
use MyParcelNL\Magento\Model\Settings\Blueprint\Group;
use MyParcelNL\Magento\Model\Settings\Blueprint\ScopeBlueprints;
use MyParcelNL\Magento\Model\Settings\Blueprint\Section;
use MyParcelNL\Magento\Service\Settings;

/**
 * A Settings over these core_config_data rows, declaring these paths. The collection applies its
 * filters like the database would, and $queries counts every collection created.
 *
 * @param array<int, array{path: string, scope: string, scope_id: int, value: ?string}> $rows
 * @param string[]                                                                      $declared
 */
function settingsOver(array $rows, array $declared, ?int &$queries): Settings
{
    $queries = 0;
    $factory = Mockery::mock(CollectionFactory::class);
    $factory->shouldReceive('create')->andReturnUsing(static function () use ($rows, &$queries) {
        $queries++;

        return new class ($rows) implements IteratorAggregate {
            private array $rows;

            private array $filters = [];

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function addFieldToFilter(string $field, $condition): self
            {
                $this->filters[$field] = $condition;

                return $this;
            }

            public function getIterator(): Iterator
            {
                return new ArrayIterator(array_map(static fn (array $row) => new DataObject($row), $this->matching()));
            }

            public function getSize(): int
            {
                return count($this->matching());
            }

            private function matching(): array
            {
                return array_values(array_filter($this->rows, function (array $row): bool {
                    foreach ($this->filters as $field => $condition) {
                        $matches = is_array($condition)
                            ? in_array($row[$field], $condition['in'], true)
                            : $row[$field] === $condition;

                        if (! $matches) {
                            return false;
                        }
                    }

                    return true;
                }));
            }
        };
    });

    $fields     = array_map(static fn (string $path) => Field::text($path, 'Insure orders up to (EU)'), $declared);
    $blueprints = Mockery::mock(ScopeBlueprints::class);
    $blueprints->shouldReceive('forScope')->andReturn(
        new Blueprint([new Section('postnl', 'PostNL settings', [new Group('options', 'Options', $fields)])])
    );

    return new Settings($blueprints, $factory);
}

function settingsRow(string $path, string $scope, int $scopeId, ?string $value = '1'): array
{
    return ['path' => $path, 'scope' => $scope, 'scope_id' => $scopeId, 'value' => $value];
}

it('answers every declared path of a scope from one query', function () {
    $settings = settingsOver([settingsRow('a/b/c', 'websites', 1)], ['a/b/c', 'a/b/d'], $queries);

    expect($settings->hasRowAtScope('a/b/c', 'websites', 1))->toBeTrue()
        ->and($settings->hasRowAtScope('a/b/d', 'websites', 1))->toBeFalse()
        ->and($queries)->toBe(1);
});

it('counts a row that stores null as a row', function () {
    $settings = settingsOver([settingsRow('a/b/c', 'websites', 1, null)], ['a/b/c'], $queries);

    expect($settings->hasRowAtScope('a/b/c', 'websites', 1))->toBeTrue();
});

it('keeps the scopes apart', function () {
    $settings = settingsOver([settingsRow('a/b/c', 'websites', 1)], ['a/b/c'], $queries);

    expect($settings->hasRowAtScope('a/b/c', 'websites', 2))->toBeFalse()
        ->and($settings->hasRowAtScope('a/b/c', 'stores', 1))->toBeFalse()
        ->and($settings->hasRowAtScope('a/b/c', 'websites', 1))->toBeTrue()
        ->and($queries)->toBe(3);
});

it('queries a path the settings file does not declare on its own', function () {
    $settings = settingsOver([settingsRow('x/y/z', 'stores', 3)], ['a/b/c'], $queries);

    expect($settings->hasRowAtScope('x/y/z', 'stores', 3))->toBeTrue()
        ->and($settings->hasRowAtScope('x/y/q', 'stores', 3))->toBeFalse()
        ->and($queries)->toBe(3);
});

it('keeps a stored null value and the paths of this scope only', function () {
    $settings = settingsOver([
        settingsRow('a/b/c', 'websites', 1, null),
        settingsRow('a/b/d', 'websites', 1, '5'),
        settingsRow('a/b/d', 'websites', 2, '7'),
    ], [], $queries);

    expect($settings->storedValuesAtScope(['a/b/c', 'a/b/d', 'a/b/e'], 'websites', 1))
        ->toBe(['a/b/c' => null, 'a/b/d' => '5']);
});

it('makes no query for no paths', function () {
    $settings = settingsOver([], [], $queries);

    expect($settings->storedValuesAtScope([], 'websites', 1))->toBe([])
        ->and($queries)->toBe(0);
});

it('names a field the way the settings form shows it', function () {
    $settings = settingsOver([], ['a/b/insurance_eu_amount'], $queries);

    expect($settings->fieldLabel('a/b/insurance_eu_amount', 'default', 0))->toBe('PostNL settings, Insure orders up to (EU)')
        ->and($settings->fieldLabel('a/b/unknown', 'default', 0))->toBeNull();
});
