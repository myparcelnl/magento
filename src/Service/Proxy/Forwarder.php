<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * Translates a Magento `RequestInterface` into the primitives
 * {@see Client::forward()} consumes (upstream path, method, header map,
 * body, query string) and wraps the resulting {@see Response} value
 * object in a Magento `Raw` result. Pure plumbing; all policy lives in
 * {@see Client}.
 */
class Forwarder
{
    private Client $client;
    private RawFactory $rawFactory;

    public function __construct(Client $client, RawFactory $rawFactory)
    {
        $this->client = $client;
        $this->rawFactory = $rawFactory;
    }

    public function forward(RequestInterface $request): Raw
    {
        $host       = (string) $request->getParam('upstream_host');
        $acceptance = (bool)   $request->getParam('upstream_acceptance');
        $path       = (string) $request->getParam('upstream_path');
        $body       = (string) $request->getContent();
        $query      = (string) $request->getServer('QUERY_STRING', '');
        $headers    = $this->collectRequestHeaders($request);

        $resp = $this->client->forward(
            $host,
            $acceptance,
            $path,
            $request->getMethod(),
            $headers,
            $body,
            $query
        );

        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($resp->status);
        foreach ($resp->headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }
        $result->setContents($resp->body);

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function collectRequestHeaders(RequestInterface $request): array
    {
        $out = [];
        if (!method_exists($request, 'getHeaders')) {
            return $out;
        }
        foreach ($request->getHeaders() as $header) {
            $out[$header->getFieldName()] = $header->getFieldValue();
        }
        return $out;
    }
}
