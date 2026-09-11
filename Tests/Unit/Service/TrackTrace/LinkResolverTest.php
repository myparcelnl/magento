<?php

declare(strict_types=1);

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Service\TrackTrace\AccountPlatform;
use MyParcelNL\Magento\Service\TrackTrace\LinkResolver;
use MyParcelNL\Magento\Service\TrackTraceUrl;

/**
 * The link an admin sees: the one the API issued where it is stored, a constructed one where it is
 * not, and no link at all where nothing can be constructed honestly.
 */
function makeLinkResolver(array $rows, ?int $platformId = null): LinkResolver
{
    $select = Mockery::mock(Select::class);
    $select->shouldReceive('from', 'joinLeft', 'where', 'order')->andReturnSelf();

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('select')->andReturn($select);
    $connection->shouldReceive('quoteInto')->andReturn('');
    $connection->shouldReceive('fetchAll')->andReturn($rows);

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(static fn(string $table): string => $table);

    $platform = Mockery::mock(AccountPlatform::class);
    $platform->shouldReceive('forStore')->andReturn($platformId);

    return new LinkResolver(
        $resource,
        $platform,
        new TrackTraceUrl('https://myparcel.me/track-trace/', [3 => 'https://sendmyparcel.me/track-trace/'])
    );
}

/** @return array<string, mixed> */
function trackRow(array $overrides = []): array
{
    return array_merge([
        'order_id'                => 7,
        'track_number'            => '3STBJG123456789',
        'myparcel_tracktrace_url' => null,
        'store_id'                => 1,
        'postcode'                => '2131 BC',
        'country_id'              => 'NL',
    ], $overrides);
}

it('uses the stored link the API issued', function () {
    $links = makeLinkResolver([trackRow(['myparcel_tracktrace_url' => 'https://myparcel.me/track-trace/abc'])]);

    expect($links->forOrders([7])[7][0]['url'])->toBe('https://myparcel.me/track-trace/abc');
});

it('constructs the link when none is stored', function () {
    $links = makeLinkResolver([trackRow()]);

    expect($links->forOrders([7])[7][0]['url'])
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL');
});

it('constructs the belgian host from the store platform', function () {
    $links = makeLinkResolver([trackRow(['postcode' => '2000', 'country_id' => 'BE'])], 3);

    expect($links->forOrders([7])[7][0]['url'])
        ->toBe('https://sendmyparcel.me/track-trace/3STBJG123456789/2000/BE');
});

it('reads an order with no country as dutch, as it did before', function () {
    $links = makeLinkResolver([trackRow(['country_id' => null])]);

    expect($links->forOrders([7])[7][0]['url'])
        ->toBe('https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL');
});

it('still lists the barcode when no url can be constructed', function () {
    $links = makeLinkResolver([trackRow(['postcode' => ''])]);
    $row   = $links->forOrders([7])[7][0];

    expect($row['url'])->toBe('')
        ->and($row['number'])->toBe('3STBJG123456789')
        ->and($links->html([$row]))->toBe('3STBJG123456789<br/>');
});

it('never links a barcode that is only a placeholder', function () {
    $links = makeLinkResolver([trackRow(['track_number' => 'printed']), trackRow(['track_number' => '–'])]);
    $rows  = $links->forOrders([7])[7];

    expect($rows[0]['url'])->toBe('')
        ->and($rows[1]['url'])->toBe('')
        ->and($links->html($rows))->toBe('printed<br/>-<br/>');
});

it('renders a real barcode as a link', function () {
    $links = makeLinkResolver([trackRow()]);

    expect($links->htmlForOrder(7))->toBe(
        '<a class="myparcel-barcode-link" target="_blank"'
        . ' href="https://myparcel.me/track-trace/3STBJG123456789/2131BC/NL">3STBJG123456789</a><br/>'
    );
});

it('gives no html for an order with no tracks', function () {
    expect(makeLinkResolver([])->htmlForOrder(7))->toBe('');
});

it('keeps every track of an order, in the order they were read', function () {
    $links = makeLinkResolver([
        trackRow(['myparcel_tracktrace_url' => 'https://myparcel.me/track-trace/one']),
        trackRow(['myparcel_tracktrace_url' => 'https://myparcel.me/track-trace/two']),
    ]);

    expect(array_column($links->forOrders([7])[7], 'url'))
        ->toBe(['https://myparcel.me/track-trace/one', 'https://myparcel.me/track-trace/two']);
});

it('takes a single track its own stored link without touching the database', function () {
    $track = Mockery::mock(Track::class);
    $track->shouldReceive('getData')->with('myparcel_tracktrace_url')->andReturn('https://myparcel.me/track-trace/stored');

    expect(makeLinkResolver([])->forTrack($track))->toBe('https://myparcel.me/track-trace/stored');
});

it('builds a single track its link from its own barcode', function () {
    $track = Mockery::mock(Track::class);
    $track->shouldReceive('getData')->with('myparcel_tracktrace_url')->andReturn(null);
    $track->shouldReceive('getOrderId')->andReturn(7);
    $track->shouldReceive('getNumber')->andReturn('3STBJG999999999');

    // The row carries an older barcode; the track's own must win.
    expect(makeLinkResolver([trackRow()])->forTrack($track))
        ->toBe('https://myparcel.me/track-trace/3STBJG999999999/2131BC/NL');
});

/*
 * The html() contract: it is echoed unescaped by the grid and by order_view.phtml, so nothing that
 * came out of the database may reach the page as markup.
 */

it('escapes a barcode payload in both the href and the link text', function () {
    $links = makeLinkResolver([trackRow(['track_number' => '<img src=x onerror=alert(1)>'])]);

    expect($links->htmlForOrder(7))
        ->not->toContain('<img')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('escapes a stored link before it reaches the href', function () {
    $links = makeLinkResolver([
        trackRow(['myparcel_tracktrace_url' => 'https://myparcel.me/"><svg/onload=alert(1)>']),
    ]);

    expect($links->htmlForOrder(7))
        ->not->toContain('<svg')
        ->not->toContain('"><');
});
