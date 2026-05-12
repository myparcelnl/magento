<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Service\Config;
use Psr\Log\LoggerInterface;

class Client
{
    private const UPSTREAM_BASE = 'https://api.myparcel.nl';
    private const TIMEOUT_SECONDS = 5;
    private const MAX_BODY_BYTES = 32768;
    private const ALLOWED_METHODS = ['GET', 'POST', 'HEAD', 'OPTIONS'];

    /**
     * Upstream path prefixes the storefront delivery-options widget needs.
     * A request whose path doesn't begin with one of these (followed by end or `/`)
     * is rejected before any upstream call. Deliberately excludes `shipments`
     * (the bare path) so the proxy cannot be abused to POST shipments under
     * our API key. If the widget needs another path, extend this list.
     */
    private const ALLOWED_PATH_PREFIXES = [
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
                'Method Not Allowed',
                'method not allowed',
                $method,
                $upstreamPath,
                ['Allow' => implode(', ', self::ALLOWED_METHODS)]
            );
        }
        if (!$this->isPathAllowed($upstreamPath)) {
            return $this->reject(403, 'Forbidden', 'path not allowed', $method, $upstreamPath);
        }
        if (strlen($requestBody) > self::MAX_BODY_BYTES) {
            return $this->reject(413, 'Content Too Large', 'request body too large', $method, $upstreamPath);
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
                ['Content-Type' => 'application/problem+json'],
                (string) json_encode([
                    'title'  => 'Bad Gateway',
                    'status' => 502,
                    'detail' => 'upstream unreachable',
                ])
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
        $normalised = ltrim($upstreamPath, '/');
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if ($normalised === $prefix || strpos($normalised, "$prefix/") === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,string> $extraHeaders
     */
    private function reject(
        int $status,
        string $title,
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
            ['Content-Type' => 'application/problem+json'] + $extraHeaders,
            (string) json_encode([
                'title'  => $title,
                'status' => $status,
                'detail' => $reason,
            ])
        );
    }
}
