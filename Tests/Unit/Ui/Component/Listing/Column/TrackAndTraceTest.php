<?php

declare(strict_types=1);

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

function makeOrderWithTrackData(string $postcode, string $trackData, string $countryId = 'NL'): Order
{
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn($countryId);
    $address->shouldReceive('getPostcode')->andReturn($postcode);

    $order = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn($address);
    $order->shouldReceive('getData')->with('track_number')->andReturn($trackData);

    return $order;
}

it('renders a usable track & trace link for an ordinary postcode', function () {
    $html = TrackAndTrace::getTrackAndTraceLinksAsHtml(
        makeOrderWithTrackData('1411CM', '["3SHOHR420090229"]')
    );

    expect($html)
        ->toContain('href="https://myparcel.me/track-trace/3SHOHR420090229/1411CM/NL"')
        ->toContain('>3SHOHR420090229</a>');
});

it('does not let a postcode break out of the href attribute', function () {
    $html = TrackAndTrace::getTrackAndTraceLinksAsHtml(
        makeOrderWithTrackData('1411"><svg/onload=alert(31337)>', '["3SHOHR420090229"]', 'DE')
    );

    expect($html)
        ->not->toContain('<svg')
        ->not->toContain('onload=')
        ->not->toContain('"><');
});

it('does not let a postcode inject a javascript: scheme into the href', function () {
    $html = TrackAndTrace::getTrackAndTraceLinksAsHtml(
        makeOrderWithTrackData('1411" href="javascript:alert(1)', '["3SHOHR420090229"]', 'DE')
    );

    expect($html)
        ->not->toContain('javascript:')
        ->toContain('1411%22href%3D%22javascript%3Aalert%281%29');

    // Exactly one href — the payload must not have added a second.
    expect(substr_count($html, 'href="'))->toBe(1);
});

it('escapes a track number payload in both the href and the link text', function () {
    $html = TrackAndTrace::getTrackAndTraceLinksAsHtml(
        makeOrderWithTrackData('1411CM', '["<img src=x onerror=alert(1)>"]')
    );

    expect($html)
        ->not->toContain('<img')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('still renders the placeholder and printed branches unchanged', function () {
    expect(TrackAndTrace::getTrackAndTraceLinksAsHtml(
        makeOrderWithTrackData('1411CM', '["' . TrackAndTrace::VALUE_EMPTY . '"]')
    ))->toBe('-<br/>');

    expect(TrackAndTrace::getTrackAndTraceLinksAsHtml(
        makeOrderWithTrackData('1411CM', '["' . TrackAndTrace::VALUE_PRINTED . '"]')
    ))->toBe(TrackAndTrace::VALUE_PRINTED . '<br/>');
});

it('returns an empty string when there is no shipping address or postcode', function () {
    $orderWithoutAddress = Mockery::mock(Order::class);
    $orderWithoutAddress->shouldReceive('getShippingAddress')->andReturn(null);

    expect(TrackAndTrace::getTrackAndTraceLinksAsHtml($orderWithoutAddress))->toBe('');
    expect(TrackAndTrace::getTrackAndTraceLinksAsHtml(makeOrderWithTrackData('', '["3SABC"]')))->toBe('');
});
