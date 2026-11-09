<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use MyParcelNL\Magento\Service\Export\LabelHttpClient;
use Psr\Http\Client\ClientInterface;

/**
 * ShipmentLabelsService reads the body after this decorator does. A stream left at EOF turns every
 * successful label fetch into "Did not receive expected pdf response".
 */
function loggingClientOver(Response $response): LabelHttpClient
{
    $httpClient = Mockery::mock(ClientInterface::class);
    $httpClient->shouldReceive('sendRequest')->andReturn($response);

    $client = new LabelHttpClient();
    setPrivateProperty($client, 'httpClient', $httpClient);

    return $client;
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

it('logs the status, content type and body shape when the answer is not a PDF', function () {
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
        ->toContain('error keys: detail')
        ->toContain('/shipment_labels/123')
        ->not->toContain('shipment is not processed yet');
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
 * Get the URI sent for a label URL. The API separates shipment ids (path) and A4 positions (query)
 * with `;`. The generated client encodes it to %3B, and the labels endpoint answers 500 to that.
 */
function uriSentFor(string $url): string
{
    $sent = null;

    $httpClient = Mockery::mock(ClientInterface::class);
    $httpClient->shouldReceive('sendRequest')->andReturnUsing(function ($request) use (&$sent) {
        $sent = (string) $request->getUri();

        return new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 label');
    });

    $client = new LabelHttpClient();
    setPrivateProperty($client, 'httpClient', $httpClient);
    $client->sendRequest(new GuzzleHttp\Psr7\Request('GET', $url));

    return $sent;
}

it('sends the separator between shipment ids unencoded', function () {
    mockLoggerFacade()->shouldNotReceive('warning');

    expect(uriSentFor('https://api.myparcel.nl/shipment_labels/239374987%3B239760474'))
        ->toBe('https://api.myparcel.nl/shipment_labels/239374987;239760474');
});

it('sends the separator between A4 positions unencoded too', function () {
    // buildQuery() percent-encodes the joined positions, and the API refuses %3B in the query with
    // the same 500 it gives the path. A real A4 export has this shape.
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
