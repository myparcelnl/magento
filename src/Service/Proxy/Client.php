<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;
use MyParcelNL\Magento\Service\Config;
use Psr\Log\LoggerInterface;

/**
 * Single security choke point for the MyParcel storefront API proxy.
 *
 * Enforces, for every proxied call: exact-match path allow-list
 * ({@see self::ALLOWED_PATHS} — sub-paths rejected; the bare `shipments`
 * path is deliberately excluded so the proxy cannot be coerced into
 * creating shipments under our key), HTTP method allow-list, inbound/
 * outbound header drop-lists, 32 KB body cap, 5 s timeout, no redirects,
 * and server-side injection of the MyParcel API key (inbound
 * `Authorization` is dropped first). All rejections return RFC 9457
 * `application/problem+json` and emit a warning log; adding a new
 * upstream call is one entry in `ALLOWED_PATHS`.
 *
 * Chosen over a dedicated per-resource `AbstractEndpoint` to keep the
 * security policy auditable in one file and to unblock the checkout
 * widget. If a specific call later needs Magento-side semantics
 * (response reshaping, per-version negotiation, write traffic, or a
 * cross-origin headless storefront), promote that call to a dedicated
 * `AbstractEndpoint`; the two patterns coexist.
 */
class Client
{
    private const UPSTREAM_BASE = 'https://api.myparcel.nl';
    private const TIMEOUT_SECONDS = 5;
    private const MAX_BODY_BYTES = 32768;
    public const ALLOWED_METHODS = ['GET', 'POST', 'HEAD', 'OPTIONS'];

    /**
     * Exact upstream paths the proxy is allowed to forward to. Anything that
     * isn't an exact match (after trimming surrounding slashes) is rejected
     * before any upstream call. Deliberately excludes `shipments` (the bare
     * path) so the proxy cannot be abused to POST shipments under our API
     * key. If the widget needs another path, add an entry here.
     */
    private const ALLOWED_PATHS = [
        'shipments/capabilities',
    ];

    /** Hop-by-hop and server-managed request headers that must not be forwarded. */
    private const REQUEST_HEADERS_DROP = [
        'host',
        'content-length',
        'connection',
        'transfer-encoding',
        'upgrade',
        'accept-encoding',
        'authorization',
        'cookie',
    ];

    /** Hop-by-hop and content-coding response headers that must not be passed back. */
    private const RESPONSE_HEADERS_DROP = [
        'transfer-encoding',
        'connection',
        'keep-alive',
        'set-cookie',
        'content-encoding',
        'content-length',
    ];

    private Config $config;
    private LoggerInterface $logger;
    private GuzzleClient $httpClient;

    public function __construct(Config $config, LoggerInterface $logger, GuzzleClient $httpClient)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
    }

    /**
     * @param array<string,string> $requestHeaders
     * @throws LocalizedException
     */
    public function forward(
        string $upstreamPath,
        string $method,
        array $requestHeaders,
        string $requestBody,
        string $queryString
    ): Response {
        $method = strtoupper($method);

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return $this->reject(
                405,
                'method not allowed',
                $method,
                $upstreamPath,
                ['Allow' => implode(', ', self::ALLOWED_METHODS)]
            );
        }
        if (!$this->isPathAllowed($upstreamPath)) {
            return $this->reject(403, 'path not allowed', $method, $upstreamPath);
        }
        if (strlen($requestBody) > self::MAX_BODY_BYTES) {
            return $this->reject(413, 'request body too large', $method, $upstreamPath);
        }

        $apiKey = (string) $this->config->getGeneralConfig('api/key');
        if ($apiKey === '') {
            throw new LocalizedException(__('MyParcel API key is not configured.'));
        }

        $url = self::UPSTREAM_BASE . '/' . ltrim($upstreamPath, '/');
        if ($queryString !== '') {
            $url .= "?$queryString";
        }

        $headers = $this->buildOutgoingHeaders($requestHeaders, $apiKey);

        $options = [
            RequestOptions::HEADERS         => $headers,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS     => false,
            RequestOptions::TIMEOUT         => self::TIMEOUT_SECONDS,
            RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::DECODE_CONTENT  => true,
        ];
        if ($requestBody !== '' && $method !== 'GET' && $method !== 'HEAD') {
            $options[RequestOptions::BODY] = $requestBody;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $this->logger->error(sprintf(
                '[MyParcel proxy] HTTP error for %s %s: %s',
                $method,
                $upstreamPath,
                $e->getMessage()
            ));
            return new Response(
                502,
                ['Content-Type' => ProblemDetails::CONTENT_TYPE],
                ProblemDetails::fromStatus(502, 'upstream unreachable')->toJsonString()
            );
        }

        return new Response(
            $response->getStatusCode(),
            $this->filterResponseHeaders($this->flattenHeaders($response->getHeaders())),
            (string) $response->getBody()
        );
    }

    /**
     * @param array<string,string> $requestHeaders
     * @return array<string,string>
     */
    private function buildOutgoingHeaders(array $requestHeaders, string $apiKey): array
    {
        $out = [];
        foreach ($requestHeaders as $name => $value) {
            if (in_array(strtolower((string) $name), self::REQUEST_HEADERS_DROP, true)) {
                continue;
            }
            $out[(string) $name] = (string) $value;
        }
        $out['Authorization'] = 'bearer ' . base64_encode($apiKey);
        return $out;
    }

    /**
     * @param array<string,string[]> $headers
     * @return array<string,string>
     */
    private function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $out[$name] = implode(', ', $values);
        }
        return $out;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private function filterResponseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), self::RESPONSE_HEADERS_DROP, true)) {
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private function isPathAllowed(string $upstreamPath): bool
    {
        return in_array(trim($upstreamPath, '/'), self::ALLOWED_PATHS, true);
    }

    /**
     * @param array<string,string> $extraHeaders
     */
    private function reject(
        int $status,
        string $reason,
        string $method,
        string $upstreamPath,
        array $extraHeaders = []
    ): Response {
        $this->logger->warning(sprintf(
            '[MyParcel proxy] rejected %s %s: %s',
            $method,
            $upstreamPath,
            $reason
        ));
        return new Response(
            $status,
            ['Content-Type' => ProblemDetails::CONTENT_TYPE] + $extraHeaders,
            ProblemDetails::fromStatus($status, $reason)->toJsonString()
        );
    }
}
