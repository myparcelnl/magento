<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\TrackTraceUrl;
use MyParcelNL\Sdk\Helper\TrackTraceUrl as SdkTrackTraceUrl;

/**
 * The SDK comparison goes at Phase 9 with the pin bump; the explicit cases below survive it.
 */
it('matches the SDK helper it replaces', function (string $barcode, string $postalCode, ?string $countryCode) {
    expect(TrackTraceUrl::create($barcode, $postalCode, $countryCode))
        ->toBe(SdkTrackTraceUrl::create($barcode, $postalCode, $countryCode));
})->with([
    ['3STBJG123456789', '2131BC', 'NL'],
    ['3STBJG123456789', '2131 BC', 'NL'],
    ['3STBJG123456789', '2131 BC', null],
    ['3STBJG123456789', '1000', 'BE'],
    ['3STBJG123456789', '', null],
]);

it('strips spaces from the postal code', function () {
    expect(TrackTraceUrl::create('3STBJG123456789', '2131 BC', 'NL'))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL');
});

it('omits the country code when none is given', function () {
    expect(TrackTraceUrl::create('3STBJG123456789', '2131BC'))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC');
});

it('treats an empty country code as absent', function () {
    expect(TrackTraceUrl::create('3STBJG123456789', '2131BC', ''))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC');
});
