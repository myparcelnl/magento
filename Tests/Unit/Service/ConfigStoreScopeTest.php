<?php

declare(strict_types=1);

// configWithStoreRows() lives in Tests/Helpers/ConfigMocks.php.

/** The current store (1) and store 5 hold different values for every path. */
function configForTwoStores(): \MyParcelNL\Magento\Service\Config
{
    return configWithStoreRows([
        1 => ['s/flag' => '0', 's/fee' => '1,00', 's/time' => '09,00,00', 's/days' => '1', 'carriers/myparcel/title' => 'One'],
        5 => ['s/flag' => '1', 's/fee' => '5,95', 's/time' => '17,30,00', 's/days' => '3', 'carriers/myparcel/title' => 'Five'],
    ]);
}

it('reads a typed setting of the given store', function (string $getter, array $args, $expected) {
    expect(configForTwoStores()->{$getter}(...array_merge($args, [5])))->toBe($expected);
})->with([
    'bool'            => ['getBoolConfig', ['s/', 'flag'], true],
    'float'           => ['getFloatConfig', ['s/', 'fee'], 5.95],
    'time'            => ['getTimeConfig', ['s/', 'time'], '17:30'],
    'integer'         => ['getIntegerConfig', ['s/', 'days'], 3],
    'magento carrier' => ['getMagentoCarrierConfig', ['title'], 'Five'],
]);

it('reads the current store without a store id', function (string $getter, array $args, $expected) {
    expect(configForTwoStores()->{$getter}(...$args))->toBe($expected);
})->with([
    'bool'            => ['getBoolConfig', ['s/', 'flag'], false],
    'float'           => ['getFloatConfig', ['s/', 'fee'], 1.0],
    'time'            => ['getTimeConfig', ['s/', 'time'], '09:00'],
    'integer'         => ['getIntegerConfig', ['s/', 'days'], 1],
    'magento carrier' => ['getMagentoCarrierConfig', ['title'], 'One'],
]);
