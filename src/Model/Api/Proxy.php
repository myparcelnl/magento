<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Webapi\Rest\Request;
use MyParcelNL\Magento\Api\ProxyInterface;
use MyParcelNL\Magento\Service\Config;

class Proxy implements ProxyInterface
{
    private const MYPARCEL_API_URL = 'https://api.myparcel.nl';

    private Config $config;
    private Curl $curl;
    private Request $request;

    public function __construct(
        Config $config,
        Curl $curl,
        Request $request
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->request = $request;
    }

    /**
     * @inheritdoc
     */
    public function forward(string $path): array
    {
        $apiKey = $this->config->getGeneralConfig('api/key');
        $fullPath = $this->extractFullPath($path);
        $url = self::MYPARCEL_API_URL . '/' . ltrim($fullPath, '/');

        // Add query parameters
        $queryParams = $this->getQueryParams();
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        // Set headers
        $this->curl->addHeader('Authorization', sprintf('bearer %s', base64_encode($apiKey)));
        $this->curl->addHeader('Content-Type', 'application/json');

        // Forward relevant headers from original request
        $this->forwardHeaders();

        // Execute request based on method
        $method = $this->request->getHttpMethod();
        if ($method === 'POST') {
            $body = $this->request->getContent();
            $this->curl->post($url, $body);
        } else {
            $this->curl->get($url);
        }

        $response = $this->curl->getBody();

        return json_decode($response, true) ?? [];
    }

    /**
     * Get query parameters from the request, excluding Magento-specific ones
     */
    private function getQueryParams(): array
    {
        $params = $this->request->getParams();

        // Remove Magento-specific parameters
        unset($params['path']);

        return $params;
    }

    /**
     * Forward relevant headers from the original request
     */
    private function forwardHeaders(): void
    {
        $headersToForward = ['Accept', 'Accept-Language'];

        foreach ($headersToForward as $headerName) {
            $value = $this->request->getHeader($headerName);
            if ($value) {
                $this->curl->addHeader($headerName, $value);
            }
        }
    }

    /**
     * Extract full path from request URI to support multi-segment paths
     */
    private function extractFullPath(string $fallbackPath): string
    {
        $requestUri = $this->request->getRequestUri();
        
        // Match everything after /myparcel/proxy/ (before query string)
        if (preg_match('#/myparcel/proxy/([^?]+)#', $requestUri, $matches)) {
            return urldecode($matches[1]);
        }

        return $fallbackPath;
    }
}
