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
use MyParcelNL\Magento\Model\Rest\ProblemDetails;
use MyParcelNL\Magento\Service\Proxy\CorsHandler;
use MyParcelNL\Magento\Service\Proxy\Forwarder;

/**
 * Storefront entry point for the MyParcel API proxy.
 *
 * Handles the CORS lifecycle via {@see CorsHandler}: answers preflight
 * requests locally with 204, enforces the Origin allow-list on real
 * requests, and delegates forwarding to {@see Forwarder}. The proxy is
 * storefront-only and anonymous — `validateForCsrf` is intentionally
 * permissive because CORS is the real authorization policy; the
 * `CsrfAwareActionInterface` is implemented only because Magento requires
 * it for non-form-key state-changing requests.
 */
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
    private CorsHandler $cors;

    public function __construct(
        RequestInterface $request,
        Forwarder $forwarder,
        RawFactory $rawFactory,
        CorsHandler $cors
    ) {
        $this->request    = $request;
        $this->forwarder  = $forwarder;
        $this->rawFactory = $rawFactory;
        $this->cors       = $cors;
    }

    public function execute(): ResultInterface
    {
        $origin = $this->cors->getRequestOrigin($this->request);

        if ($this->cors->isPreflight($this->request)) {
            if (!$this->cors->isAllowedOrigin($origin)) {
                return $this->forbidden();
            }
            return $this->cors->buildPreflightResponse($this->request, $origin);
        }

        if (!$this->cors->isAllowedOrigin($origin)) {
            return $this->forbidden();
        }

        $response = $this->forwarder->forward($this->request);
        $this->cors->applyCorsHeaders($response, $origin);
        return $response;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    private function forbidden(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(403);
        $result->setHeader('Content-Type', ProblemDetails::CONTENT_TYPE, true);
        $result->setContents(ProblemDetails::fromStatus(403, 'origin not allowed')->toJsonString());
        return $result;
    }
}
