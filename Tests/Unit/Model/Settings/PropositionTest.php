<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Settings\Proposition;

/** The one list of propositions: every entry is complete, and an unknown id has none. */

it('gives every proposition a name and a home country', function (int $id) {
    $proposition = Proposition::forId($id);

    expect($proposition->getId())->toBe($id)
        ->and($proposition->getName())->not->toBe('')
        ->and($proposition->getCountryCode())->toMatch('/^[A-Z]{2}$/');
})->with(Proposition::ids());

it('gives a track & trace host with a trailing slash, where there is one', function (int $id) {
    $url = Proposition::forId($id)->getTrackTraceUrl();

    expect(null === $url || (str_starts_with($url, 'https://') && str_ends_with($url, '/')))->toBeTrue();
})->with(Proposition::ids());

it('reads the propositions the module knows', function () {
    expect([Proposition::forId(1)->getName(), Proposition::forId(1)->getCountryCode()])->toBe(['myparcel', 'NL'])
        ->and([Proposition::forId(3)->getName(), Proposition::forId(3)->getCountryCode()])->toBe(['belgie', 'BE'])
        ->and([Proposition::forId(6)->getName(), Proposition::forId(6)->getCountryCode()])->toBe(['italy', 'IT']);
});

it('gives italy no track & trace host, because the api always returns the link', function () {
    expect(Proposition::forId(6)->getTrackTraceUrl())->toBeNull();
});

it('answers no proposition for an unknown id and for no id', function (?int $id) {
    expect(Proposition::forId($id))->toBeNull();
})->with([[null], [99]]);

it('defaults to myparcel', function () {
    expect(Proposition::default()->getId())->toBe(1)
        ->and(Proposition::default()->getName())->toBe('myparcel');
});
