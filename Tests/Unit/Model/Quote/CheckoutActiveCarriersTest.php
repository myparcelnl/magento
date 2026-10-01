<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Service\Config;

/**
 * @param string[] $contracted v2 carrier names in the store's contract; empty reads permissive
 * @param string[] $switchedOn module carrier names whose delivery toggle is on
 */
function activeCarriersFor(array $contracted, array $switchedOn): array
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getBoolConfig')->andReturnUsing(static function (string $path, string $key) use ($switchedOn): bool {
        return 'delivery/active' === $key && in_array(Config::carrierFromPath($path . $key), $switchedOn, true);
    });

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'storeId', 1);
    setPrivateProperty($checkout, 'contractDefinitions', storedContractFor(1, $contracted));

    return $checkout->getActiveCarriers();
}

it('offers a contracted carrier the SDK knows once it is switched on', function () {
    expect(activeCarriersFor(['POSTNL', 'CHEAP_CARGO'], ['postnl', 'cheapcargo']))
        ->toBe(['postnl', 'cheapcargo']);
});

it('never offers a carrier the SDK does not know, even with its toggle on', function () {
    expect(activeCarriersFor(['POSTNL', 'HOOPLA'], ['postnl', 'hoopla']))->toBe(['postnl']);
});

it('never offers a carrier the contract lacks, even with its toggle on', function () {
    expect(activeCarriersFor(['POSTNL'], ['postnl', 'gls']))->toBe(['postnl']);
});

it('offers no carrier when the contract could not be read', function () {
    expect(activeCarriersFor([], ['postnl', 'dpd']))->toBe([]);
});
