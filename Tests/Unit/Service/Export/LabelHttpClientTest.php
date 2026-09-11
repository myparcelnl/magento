<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use MyParcelNL\Magento\Service\Export\LabelHttpClient;
use Psr\Http\Client\ClientInterface;

/**
 * ShipmentLabelsService reads the body *after* this decorator does, so the read position matters as
 * much as the logging: leaving the stream at EOF would turn every successful label fetch into
 * "Did not receive expected pdf response" — the very failure this exists to explain.
 */
function loggingClientOver(Response $response): LabelHttpClient
{
    $inner = Mockery::mock(ClientInterface::class);
    $inner->shouldReceive('sendRequest')->andReturn($response);

    return new LabelHttpClient($inner);
}

function labelRequest(): GuzzleHttp\Psr7\Request
{
    return new GuzzleHttp\Psr7\Request('GET', 'https://api.myparcel.nl/shipment_labels/123');
}

it('hands a PDF through with the body still readable', function () {
    mockLoggerFacade()->shouldNotReceive('warning');

    $pdf      = "%PDF-1.4\nlabel bytes";
    $response = loggingClientOver(new Response(200, ['Content-Type' => 'application/pdf'], $pdf))
        ->sendRequest(labelRequest());

    expect((string) $response->getBody())->toBe($pdf);
});

it('logs the status, content type and body when the answer is not a PDF', function () {
    $logged = null;
    mockLoggerFacade()->shouldReceive('warning')->once()->andReturnUsing(
        function (string $message) use (&$logged): void {
            $logged = $message;
        }
    );

    loggingClientOver(new Response(
        500,
        ['Content-Type' => 'application/json'],
        '{"errors":[{"detail":"shipment is not processed yet"}]}'
    ))->sendRequest(labelRequest());

    expect($logged)
        ->toContain('HTTP 500')
        ->toContain('application/json')
        ->toContain('shipment is not processed yet')
        ->toContain('/shipment_labels/123');
});

it('leaves a non-PDF body readable too, so the SDK still sees what it refused', function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    $body     = '{"message":"nope"}';
    $response = loggingClientOver(new Response(422, [], $body))->sendRequest(labelRequest());

    expect((string) $response->getBody())->toBe($body);
});

it('restores an unseekable body instead of losing it', function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    // A streamed response cannot be rewound, so the decorator has to put the bytes back itself.
    $body     = '{"message":"nope"}';
    $unseekable = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
    $unseekable->write($body);
    $unseekable->rewind();

    $response = loggingClientOver(new Response(422, [], $unseekable))->sendRequest(labelRequest());

    expect((string) $response->getBody())->toBe($body);
});

/**
 * The API documents `;` as the separator for shipment ids (path) and A4 positions (query), and the
 * SDK joins both correctly — but the generated client percent-encodes them to %3B, which the labels
 * endpoint answers 500 to wherever it appears. beta.15 built the URL by hand and never encoded
 * anything, so this only broke at the v11 migration.
 */
function uriSentFor(string $url): string
{
    $sent = null;

    $inner = Mockery::mock(ClientInterface::class);
    $inner->shouldReceive('sendRequest')->andReturnUsing(function ($request) use (&$sent) {
        $sent = (string) $request->getUri();

        return new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 label');
    });

    (new MyParcelNL\Magento\Service\Export\LabelHttpClient($inner))
        ->sendRequest(new GuzzleHttp\Psr7\Request('GET', $url));

    return $sent;
}

it('sends the separator between shipment ids unencoded', function () {
    mockLoggerFacade()->shouldNotReceive('warning');

    expect(uriSentFor('https://api.myparcel.nl/shipment_labels/239374987%3B239760474'))
        ->toBe('https://api.myparcel.nl/shipment_labels/239374987;239760474');
});

it('sends the separator between A4 positions unencoded too', function () {
    // buildQuery() percent-encodes the joined positions, and the API refuses %3B in the query with
    // the same 500 it gives the path — the shape a real A4 export produces.
    mockLoggerFacade()->shouldNotReceive('warning');

    expect(uriSentFor('https://api.myparcel.nl/shipment_labels/1%3B2?format=A4&positions=2%3B4'))
        ->toBe('https://api.myparcel.nl/shipment_labels/1;2?format=A4&positions=2;4');
});

it('logs the repaired uri it sent, query included, when the answer is not a PDF', function () {
    // The diagnosis starts from this line; the encoded original would point at the wrong request.
    $logged = null;
    mockLoggerFacade()->shouldReceive('warning')->once()->andReturnUsing(
        function (string $message) use (&$logged): void {
            $logged = $message;
        }
    );

    loggingClientOver(new Response(500, [], '{"errors":[{"code":400}]}'))->sendRequest(
        new GuzzleHttp\Psr7\Request('GET', 'https://api.myparcel.nl/shipment_labels/1%3B2?format=A4&positions=2%3B4')
    );

    expect($logged)->toContain('/shipment_labels/1;2?format=A4&positions=2;4');
});

it('leaves a single shipment id alone', function () {
    // One id has no separator to repair, which is why single labels worked all along.
    mockLoggerFacade()->shouldNotReceive('warning');

    expect(uriSentFor('https://api.myparcel.nl/shipment_labels/239374987'))
        ->toBe('https://api.myparcel.nl/shipment_labels/239374987');
});
