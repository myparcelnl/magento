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
use MyParcelNL\Magento\Service\ProxyForwarder;

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
    private ProxyForwarder $forwarder;
    private RawFactory $rawFactory;
    private StoreManagerInterface $storeManager;
    private SessionConfigInterface $sessionConfig;
    private CookieManagerInterface $cookieManager;

    public function __construct(
        Context $context,
        ProxyForwarder $forwarder,
        RawFactory $rawFactory,
        StoreManagerInterface $storeManager,
        SessionConfigInterface $sessionConfig,
        CookieManagerInterface $cookieManager
    ) {
        parent::__construct($context);
        $this->forwarder = $forwarder;
        $this->rawFactory = $rawFactory;
        $this->storeManager = $storeManager;
        $this->sessionConfig = $sessionConfig;
        $this->cookieManager = $cookieManager;
    }

    public function execute(): ResultInterface
    {
        $request = $this->getRequest();
        if (!$this->gatesPass($request)) {
            return $this->forbidden();
        }
        return $this->forwarder->forward($request);
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

    private function forbidden(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(403);
        $result->setHeader('Content-Type', 'application/json', true);
        $result->setContents('{"error":"forbidden"}');
        return $result;
    }
}
