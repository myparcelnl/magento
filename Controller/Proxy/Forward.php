<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Proxy;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpDeleteActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpHeadActionInterface;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\Action\HttpPatchActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpPutActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Service\ApiProxy;

class Forward extends Action implements
    CsrfAwareActionInterface,
    HttpGetActionInterface,
    HttpPostActionInterface,
    HttpPutActionInterface,
    HttpDeleteActionInterface,
    HttpPatchActionInterface,
    HttpHeadActionInterface,
    HttpOptionsActionInterface
{
    public function __construct(
        Context $context,
        private readonly ApiProxy $apiProxy,
        private readonly RawFactory $rawFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly SessionConfigInterface $sessionConfig,
        private readonly CookieManagerInterface $cookieManager
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if (!$this->gatesPass($this->getRequest())) {
            return $this->forbidden();
        }
        return $this->doForward($this->getRequest());
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->gatesPass($request);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException($this->forbidden(), [__('Forbidden.')]);
    }

    private function gatesPass(RequestInterface $request): bool
    {
        return $this->originMatchesBaseUrl($request) && $this->hasSessionCookie();
    }

    private function originMatchesBaseUrl(RequestInterface $request): bool
    {
        $origin = (string) ($request->getHeader('Origin') ?: '');
        if ($origin === '') {
            $origin = (string) ($request->getHeader('Referer') ?: '');
        }
        if ($origin === '') {
            return false;
        }

        $expected = (string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
        return $this->sameSchemeHostPort($origin, $expected);
    }

    private function sameSchemeHostPort(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (!is_array($pa) || !is_array($pb)) {
            return false;
        }
        $schemeA = $pa['scheme'] ?? '';
        $schemeB = $pb['scheme'] ?? '';
        $portA   = $pa['port'] ?? ($schemeA === 'https' ? 443 : 80);
        $portB   = $pb['port'] ?? ($schemeB === 'https' ? 443 : 80);
        return $schemeA === $schemeB
            && ($pa['host'] ?? '') === ($pb['host'] ?? '')
            && $portA === $portB;
    }

    private function hasSessionCookie(): bool
    {
        $name  = (string) $this->sessionConfig->getName();
        $value = $this->cookieManager->getCookie($name);
        return $value !== null && $value !== '';
    }

    private function doForward(RequestInterface $request): Raw
    {
        $path    = (string) $request->getParam('upstream_path');
        $body    = (string) $request->getContent();
        $query   = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $headers = $this->collectRequestHeaders($request);

        $resp = $this->apiProxy->forward($path, $request->getMethod(), $headers, $body, $query);

        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($resp->status);
        foreach ($resp->headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }
        $result->setContents($resp->body);
        return $result;
    }

    private function forbidden(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(403);
        $result->setHeader('Content-Type', 'application/json', true);
        $result->setContents('{"error":"forbidden"}');
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
