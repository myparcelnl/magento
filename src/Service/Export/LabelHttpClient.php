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
 * Repairs the labels request on the way out and explains the answer on the way back; the PSR client
 * is the only seam ShipmentLabelsService offers.
 *
 * Outbound it restores the `;` separators — shipment ids in the path, A4 positions in the query —
 * that the generated client encodes to `%3B` (SDK issue 4 — the endpoint answers 500 to either).
 * Inbound it logs a non-PDF body before the
 * SDK throws it away. A real PDF is never copied: only the first bytes are read, and only a response
 * already known not to be a PDF is materialised. The Authorization header is never touched or logged.
 */
class LabelHttpClient implements ClientInterface
{
    private const EXCERPT_LENGTH = 500;

    /** Enough for the `%PDF-1` signature the SDK matches on. */
    private const SIGNATURE_LENGTH = 8;

    private ClientInterface $inner;

    public function __construct(?ClientInterface $inner = null)
    {
        $this->inner = $inner ?? new GuzzleClient(['timeout' => ShipmentApiFactory::DEFAULT_HTTP_TIMEOUT]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $sent     = $this->withRawSeparators($request);
        $response = $this->inner->sendRequest($sent);
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
            substr($body, 0, self::EXCERPT_LENGTH)
        ));

        return $this->restored($response, $body);
    }

    /**
     * Puts the documented `;` back between shipment ids in the path, and between A4 positions in the
     * query — the API's parser splits on the literal character before percent-decoding, so an
     * encoded `%3B` anywhere in the URL answers HTTP 500. PSR-7 does not re-encode the repair: `;`
     * is an allowed sub-delimiter in both path and query. No parameter of this endpoint carries a
     * semicolon as data, so the replacement cannot corrupt a value.
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
