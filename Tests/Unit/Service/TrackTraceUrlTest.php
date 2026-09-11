<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\TrackTraceUrl;

/**
 * The fallback url, for shipments stored before the module read the link from the API.
 *
 * The platform cases are the Belgian regression: one build now serves both countries, so the host
 * follows the account rather than a single configured value.
 */
function trackTraceUrl(): TrackTraceUrl
{
    return new TrackTraceUrl(
        'https://myparcel.me/track-trace/',
        [3 => 'https://sendmyparcel.me/track-trace/']
    );
}

it('strips spaces from the postal code', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '2131 BC', 'NL'))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL');
});

it('omits the country code when none is given', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '2131BC'))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC');
});

it('treats an empty country code as absent', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '2131BC', ''))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC');
});

it('answers the belgian portal for a belgian account', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '2000', 'BE', 3))
        ->toBe('https://sendmyparcel.me/track-trace/3STBJG123456789/2000/BE');
});

it('keeps the default host for a platform that has no entry', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '2131BC', 'NL', 2))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL');
});

it('keeps the default host when the platform is unknown', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '2131BC', 'NL', null))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL');
});

it('defaults to the dutch portal with no arguments at all', function () {
    expect((new TrackTraceUrl())->create('3STBJG123456789', '2131BC', 'NL', 3))
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL');
});

/*
 * Injection cases. Every part is a path segment, so a payload in one must not be able to end the
 * segment, add an attribute, or swap the scheme. Ported from the TrackAndTrace column test that
 * covered this before the link moved here.
 */

it('does not let a postcode break out of the href attribute', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '1411"><svg/onload=alert(31337)>', 'DE'))
        ->not->toContain('<svg')
        ->not->toContain('"><');
});

it('does not let a postcode inject a javascript scheme into the href', function () {
    expect(trackTraceUrl()->create('3STBJG123456789', '1411" href="javascript:alert(1)', 'DE'))
        ->not->toContain('javascript:')
        ->toContain('1411%22href%3D%22javascript%3Aalert%281%29');
});

it('encodes a barcode payload as well', function () {
    expect(trackTraceUrl()->create('<img src=x onerror=alert(1)>', '2131BC', 'NL'))
        ->toBe('https://myparcel.me/track-trace/%3Cimg%20src%3Dx%20onerror%3Dalert%281%29%3E/2131BC/NL');
});
