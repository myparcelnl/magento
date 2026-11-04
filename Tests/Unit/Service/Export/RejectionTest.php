<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\Rejection;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ApiException;

// builtShipmentFor() lives in Tests/Helpers/ExportFixtures.php.

function rejectionFor(array $body, int $status = 422, array $chunk = []): Rejection
{
    return Rejection::fromApiException(
        new ApiException('refused', $status, null, json_encode($body)),
        $chunk ?: [builtShipmentFor('key', '000000114'), builtShipmentFor('key', '000000115')]
    );
}

it('pins each error on the order its pointer names', function () {
    $rejection = rejectionFor([
        'detail' => 'Validation failed',
        'errors' => [
            ['title' => 'Invalid postal code', 'detail' => 'Country is required',
             'instance' => '/data/shipments/0/recipient/postal_code'],
        ],
    ]);

    expect($rejection->blames('000000114'))->toBeTrue()
        ->and($rejection->blames('000000115'))->toBeFalse()
        ->and($rejection->reasons()['000000114'][0])->toContain('Country is required')
        ->and($rejection->reasons()['000000114'][0])->toContain('recipient.postal_code');
});

/**
 * One shipment can break several rules at once, and the merchant has to fix all of them before the
 * batch goes through. Keeping only the last reason meant a second fix-and-retry.
 */
it('keeps every reason given for one order', function () {
    $rejection = rejectionFor([
        'errors' => [
            ['detail' => 'Country is required', 'instance' => '/data/shipments/0/recipient/cc'],
            ['detail' => 'Street is required', 'instance' => '/data/shipments/0/recipient/street'],
        ],
    ]);

    expect($rejection->reasons()['000000114'])->toHaveCount(2);
});

it('puts an error that names no shipment into the summary rather than on every order', function () {
    $rejection = rejectionFor([
        'detail' => 'Account problem',
        'errors' => [['detail' => 'your account is not active']],
    ]);

    expect($rejection->reasons())->toBeEmpty()
        ->and($rejection->summary())->toContain('Account problem')
        ->and($rejection->summary())->toContain('your account is not active');
});

/**
 * A 422 means the API validated and created nothing. Anything else may have been processed, and the
 * API deduplicates nothing, so a retry would risk a second billable shipment.
 */
it('is retryable only on a 422 that named someone', function () {
    $blaming = ['errors' => [['detail' => 'nope', 'instance' => '/data/shipments/0/recipient/cc']]];

    expect(rejectionFor($blaming, 422)->isRetryable())->toBeTrue()
        ->and(rejectionFor($blaming, 500)->isRetryable())->toBeFalse()
        ->and(rejectionFor(['detail' => 'whole batch'], 422)->isRetryable())->toBeFalse();
});

it('falls back to the exception message when the body carries no summary', function () {
    expect(Rejection::fromApiException(new ApiException('transport exploded', 0), [])->summary())
        ->toBe('transport exploded');
});

/**
 * The line that keeps the undocumented body shape visible must not reproduce it: the API quotes the
 * field it refused, and on an address that is the consumer's data.
 */
it('describes a rejection body by its shape, never by its values', function () {
    $shape = Rejection::shapeOf(json_encode([
        'type'   => 'https://api.myparcel.nl/errors/validation',
        'detail' => "postal code '1234 AB' is not valid for NL",
        'errors' => [
            ['title' => 'Invalid postal code', 'detail' => "'1234 AB' is not valid",
             'instance' => '/data/shipments/0/recipient/postal_code'],
        ],
    ]));

    expect($shape)->toContain('type,detail,errors')
        ->toContain('/data/shipments/0/recipient/postal_code')
        ->and($shape)->not->toContain('1234 AB')
        ->and($shape)->not->toContain('is not valid');
});

it('says so when a rejection body does not parse, without echoing it', function () {
    expect(Rejection::shapeOf('<html>Gateway Timeout for 1234 AB</html>'))
        ->toBe('(unparsable, 40 bytes)');
});
