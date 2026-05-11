<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class ApiProxy
{
    private const UPSTREAM_BASE = 'https://api.myparcel.nl';
    private const TIMEOUT_SECONDS = 30;

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

    /** Hop-by-hop and decoded-by-cURL response headers that must not be passed back. */
    private const RESPONSE_HEADERS_DROP = [
        'transfer-encoding',
        'connection',
        'keep-alive',
        'set-cookie',
        'content-encoding',
        'content-length',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,string> $requestHeaders
     */
    public function forward(
        string $upstreamPath,
        string $method,
        array $requestHeaders,
        string $requestBody,
        string $queryString
    ): ProxyResponse {
        $apiKey = (string) $this->config->getGeneralConfig('api/key');
        if ($apiKey === '') {
            throw new LocalizedException(__('MyParcel API key is not configured.'));
        }

        $url = self::UPSTREAM_BASE . '/' . ltrim($upstreamPath, '/');
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $method = strtoupper($method);
        $headers = $this->buildOutgoingHeaders($requestHeaders, $apiKey);

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $len  = strlen($header);
                $line = trim($header);
                if ($line === '' || stripos($line, 'HTTP/') === 0) {
                    return $len;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        if ($requestBody !== '' && $method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        }

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errstr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            $this->logger->error(sprintf(
                '[MyParcel proxy] cURL error %d for %s %s: %s',
                $errno,
                $method,
                $upstreamPath,
                $errstr
            ));
            return new ProxyResponse(
                502,
                ['Content-Type' => 'application/json'],
                '{"error":"upstream unreachable"}'
            );
        }

        return new ProxyResponse(
            $status,
            $this->filterResponseHeaders($responseHeaders),
            (string) $body
        );
    }

    /**
     * @param array<string,string> $requestHeaders
     * @return string[]
     */
    private function buildOutgoingHeaders(array $requestHeaders, string $apiKey): array
    {
        $out = [];
        foreach ($requestHeaders as $name => $value) {
            if (in_array(strtolower((string) $name), self::REQUEST_HEADERS_DROP, true)) {
                continue;
            }
            $out[] = $name . ': ' . $value;
        }
        $out[] = 'Authorization: bearer ' . base64_encode($apiKey);
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
}
