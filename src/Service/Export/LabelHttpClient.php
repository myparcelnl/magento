<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Utils;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Repairs the labels request on the way out and logs a bad answer on the way back.
 *
 * Outbound it restores the `;` separators that the generated client encodes to `%3B` (SDK issue 4):
 * the endpoint answers 500 to an encoded separator.
 * Inbound it logs only the shape of a non-PDF body: the values can quote recipient data.
 * It never copies a real PDF and never touches or logs the Authorization header.
 */
class LabelHttpClient implements ClientInterface
{
    /** Enough for the `%PDF-1` signature the SDK matches on. */
    private const SIGNATURE_LENGTH = 8;

    private ClientInterface $httpClient;

    public function __construct()
    {
        $this->httpClient = new GuzzleClient(['timeout' => ShipmentApiFactory::DEFAULT_HTTP_TIMEOUT]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $sent     = $this->withRawSeparators($request);
        $response = $this->httpClient->sendRequest($sent);
        $stream   = $response->getBody();

        if ($stream->isSeekable()) {
            $signature = $stream->read(self::SIGNATURE_LENGTH);
            $stream->rewind();

            if (preg_match('/^%PDF-\d/', $signature)) {
                return $response;
            }
        }

        $body = (string) $stream;

        if (preg_match('/^%PDF-\d/', $body)) {
            return $this->restored($response, $body);
        }

        // The *sent* URI, query included: the repair above changes it, and an encoding the API
        // refused has to be visible here or the next diagnosis starts from the wrong request.
        $uri = $sent->getUri();

        Logger::warning(sprintf(
            'MyParcel labels: expected a PDF from %s but got HTTP %d, content-type %s: %s',
            $uri->getPath() . ('' !== $uri->getQuery() ? '?' . $uri->getQuery() : ''),
            $response->getStatusCode(),
            $response->getHeaderLine('Content-Type') ?: 'none',
            Rejection::shapeOf($body)
        ));

        return $this->restored($response, $body);
    }

    /**
     * Put the literal `;` back between shipment ids in the path and A4 positions in the query.
     * The API splits before it percent-decodes, so `%3B` answers HTTP 500. No parameter of this
     * endpoint holds a semicolon as data, so the replacement cannot corrupt a value.
     */
    private function withRawSeparators(RequestInterface $request): RequestInterface
    {
        $uri   = $request->getUri();
        $path  = str_replace('%3B', ';', $uri->getPath());
        $query = str_replace('%3B', ';', $uri->getQuery());

        if ($path === $uri->getPath() && $query === $uri->getQuery()) {
            return $request;
        }

        return $request->withUri($uri->withPath($path)->withQuery($query));
    }

    /** The service reads the body after us, so it has to start where it would have. */
    private function restored(ResponseInterface $response, string $body): ResponseInterface
    {
        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();

            return $response;
        }

        return $response->withBody(Utils::streamFor($body));
    }
}
