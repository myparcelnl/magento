<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Sdk\Exception\InvalidConsignmentException;
use MyParcelNL\Sdk\Model\Recipient;

/**
 * setShippingRecipient() delegates the actual street splitting to the SDK's
 * SplitStreet helper, so what belongs to Phase 1 is our wiring around it:
 * joining the street lines before handing them over, mapping each address
 * field onto the matching recipient field, taking the person from the
 * billing address, and letting a rejected address surface rather than be
 * swallowed. None of the cases below asserts a literal the SDK's NL regex
 * produced — those values are the SDK's to change.
 */
function createOrderCollectionForShippingRecipient(
    array $addressOverrides,
    array $orderOverrides = []
): MagentoOrderCollection {
    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'order', createOrder(array_merge(
        ['getShippingAddress' => createAddress($addressOverrides)],
        $orderOverrides
    )));

    return $collection;
}

function recipientForAddress(array $addressOverrides, array $orderOverrides = []): Recipient
{
    $collection = createOrderCollectionForShippingRecipient($addressOverrides, $orderOverrides);
    $collection->setShippingRecipient();

    return $collection->getShippingRecipient();
}

it('joins a multi-line street before splitting it', function () {
    // Magento stores one array entry per street line. They have to be joined
    // first, or a house number on the second line never reaches the splitter.
    // Asserted against the single-line equivalent rather than literal parts,
    // so the SDK's split rules stay untested here.
    $multiLine  = recipientForAddress(['getCountryId' => 'NL', 'street' => ['Hoofdstraat', '15A']]);
    $singleLine = recipientForAddress(['getCountryId' => 'NL', 'street' => 'Hoofdstraat 15A']);

    expect($multiLine->getNumber())->not->toBe(''); // guards against both sides being equally unsplit
    expect($multiLine->getStreet())->toBe($singleLine->getStreet());
    expect($multiLine->getNumber())->toBe($singleLine->getNumber());
    expect($multiLine->getNumberSuffix())->toBe($singleLine->getNumberSuffix());
});

it('maps each shipping address field onto the recipient', function () {
    // A US destination has no split rule, so the street passes through
    // untouched and this stays a pure field-mapping assertion.
    $recipient = recipientForAddress([
        'getCountryId' => 'US',
        'getCity'      => 'Springfield',
        'getCompany'   => 'Acme Inc',
        'getEmail'     => 'buyer@example.com',
        'getPostcode'  => '62704',
        'getTelephone' => '+1 555 0100',
        'street'       => '742 Evergreen Terrace',
    ]);

    expect($recipient->getCc())->toBe('US');
    expect($recipient->getCity())->toBe('Springfield');
    expect($recipient->getCompany())->toBe('Acme Inc');
    expect($recipient->getEmail())->toBe('buyer@example.com');
    expect($recipient->getPostalCode())->toBe('62704');
    expect($recipient->getPhone())->toBe('+1 555 0100');
});

it('keeps the full street verbatim for a destination with no split rule', function () {
    $recipient = recipientForAddress(['getCountryId' => 'US', 'street' => '742 Evergreen Terrace']);

    expect($recipient->getStreet())->toBe('742 Evergreen Terrace');
});

it('takes the person from the billing address name parts', function () {
    $recipient = recipientForAddress(
        ['getCountryId' => 'US', 'street' => '742 Evergreen Terrace'],
        ['getBillingAddress' => createAddress([
            'getFirstname'  => 'Jan',
            'getMiddlename' => 'de',
            'getLastname'   => 'Vries',
        ])]
    );

    expect($recipient->getPerson())->toBe('Jan de Vries');
});

it('lets a rejected address surface instead of swallowing it', function () {
    $collection = createOrderCollectionForShippingRecipient(['getCountryId' => 'NL', 'street' => '1234']);

    expect(fn () => $collection->setShippingRecipient())->toThrow(InvalidConsignmentException::class);
});
