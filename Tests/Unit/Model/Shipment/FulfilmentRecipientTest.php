<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\FulfilmentOrderBuilder;
use MyParcelNL\Sdk\Model\Recipient;

/**
 * The street splitting itself is the SDK's, so these test our wiring around
 * it: joining the lines, mapping the fields, taking the person from the
 * billing address, splitting by the carrier's own local country, and letting
 * a rejected address surface.
 *
 * No case asserts a literal the SDK's regex produced — those are its to change.
 */
function recipientForAddress(
    array   $addressOverrides,
    array   $orderOverrides = [],
    ?string $carrier = Carrier::POSTNL
): Recipient
{
    $order = createOrder(array_merge(
        ['getShippingAddress' => createAddress($addressOverrides)],
        $orderOverrides
    ));

    return invokePrivateMethod(
        newInstanceWithoutConstructor(FulfilmentOrderBuilder::class),
        'shippingRecipient',
        [$order, $carrier]
    );
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

it('splits a Belgian address by the carrier\'s own country, not always by PostNL', function () {
    // A Belgian destination only has a split rule when the carrier ships from Belgium. This path
    // hard-coded PostNL for every carrier, so a DPD address stayed one unsplit string.
    $belgianAddress = ['getCountryId' => 'BE', 'street' => 'Antwerpsesteenweg 20'];

    $viaDpd    = recipientForAddress($belgianAddress, [], Carrier::DPD);
    $viaPostnl = recipientForAddress($belgianAddress, [], Carrier::POSTNL);

    expect($viaDpd->getNumber())->toBe('20');
    expect($viaPostnl->getNumber())->toBe('');
});

it('lets a rejected address surface instead of swallowing it', function () {
    // beta.31 deleted InvalidConsignmentException along with the consignment stack; SplitStreet now
    // throws a plain InvalidArgumentException. What is asserted is unchanged — the address is not
    // swallowed — only the class it arrives as.
    $reject = fn () => recipientForAddress(['getCountryId' => 'NL', 'street' => '1234']);

    expect($reject)->toThrow(InvalidArgumentException::class, 'Invalid full street supplied');
});
