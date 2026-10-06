<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\AccountSettings\AccountProposition;

/** The constructor is skipped: it reads the checkout session. */
function checkoutPropositionNameFor(?int $propositionId): string
{
    $accountProposition = Mockery::mock(AccountProposition::class);
    $accountProposition->shouldReceive('forStore')->with(1)->andReturn(Proposition::forId($propositionId));

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'storeId', 1);
    setPrivateProperty($checkout, 'accountProposition', $accountProposition);

    return invokePrivateMethod($checkout, 'propositionName');
}

it('gives the widget the proposition of the store its account', function (int $id, string $name) {
    expect(checkoutPropositionNameFor($id))->toBe($name);
})->with([[1, 'myparcel'], [3, 'belgie'], [6, 'italy']]);

it('gives the widget the default proposition when the store has none the module lists', function (?int $id) {
    expect(checkoutPropositionNameFor($id))->toBe('myparcel');
})->with([[null], [99]]);
