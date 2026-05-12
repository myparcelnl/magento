<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Proxy;

use Magento\Framework\App\Action\HttpDeleteActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
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
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;
use MyParcelNL\Magento\Service\Proxy\Forwarder;

class Forward implements
    CsrfAwareActionInterface,
    HttpGetActionInterface,
    HttpPostActionInterface,
    HttpPutActionInterface,
    HttpDeleteActionInterface,
    HttpPatchActionInterface,
    HttpOptionsActionInterface
{
    private RequestInterface $request;
    private Forwarder $forwarder;
    private RawFactory $rawFactory;
    private StoreManagerInterface $storeManager;

    public function __construct(
        RequestInterface $request,
        Forwarder $forwarder,
        RawFactory $rawFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->request = $request;
        $this->forwarder = $forwarder;
        $this->rawFactory = $rawFactory;
        $this->storeManager = $storeManager;
    }

    public function execute(): ResultInterface
    {
        if (!$this->originMatchesBaseUrl($this->request)) {
            return $this->forbidden();
        }
        return $this->forwarder->forward($this->request);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->originMatchesBaseUrl($request);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException($this->forbidden(), [__('Forbidden.')]);
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

    private function forbidden(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(403);
        $result->setHeader('Content-Type', ProblemDetails::CONTENT_TYPE, true);
        $result->setContents(ProblemDetails::fromStatus(403, 'origin does not match base URL')->toJsonString());
        return $result;
    }
}
