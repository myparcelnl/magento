<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\TrackTraceUrl;

/** The SDK comparison goes at Phase 9 with the pin bump; the explicit cases below survive it. */
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
