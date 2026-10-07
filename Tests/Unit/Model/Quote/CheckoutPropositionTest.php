<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;

/** The constructor is skipped: it reads the checkout session. */
function checkoutPropositionNameFor(?int $propositionId): string
{
    $storedAccount = Mockery::mock(StoredAccount::class);
    $storedAccount->shouldReceive('propositionForStore')->with(1)->andReturn(Proposition::forId($propositionId));

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'storeId', 1);
    setPrivateProperty($checkout, 'storedAccount', $storedAccount);

    return invokePrivateMethod($checkout, 'propositionName');
}

it('gives the widget the proposition of the store its account, or the default one', function (?int $id, string $name) {
    expect(checkoutPropositionNameFor($id))->toBe($name);
})->with([
    'listed'   => [3, 'belgie'],
    'unlisted' => [99, 'myparcel'],
]);
