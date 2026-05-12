<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

class ProxyForwarder
{
    private ApiProxy $apiProxy;
    private RawFactory $rawFactory;

    public function __construct(ApiProxy $apiProxy, RawFactory $rawFactory)
    {
        $this->apiProxy = $apiProxy;
        $this->rawFactory = $rawFactory;
    }

    public function forward(RequestInterface $request): Raw
    {
        $path    = (string) $request->getParam('upstream_path');
        $body    = (string) $request->getContent();
        $query   = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $headers = $this->collectRequestHeaders($request);

        $resp = $this->apiProxy->forward(
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
