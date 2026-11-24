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
use Magento\Framework\Controller\ResultInterface;
use MyParcelNL\Magento\Service\Proxy\CorsHandler;
use MyParcelNL\Magento\Service\Proxy\Forwarder;

/**
 * Storefront entry point for the MyParcel API proxy.
 *
 * Delegates CORS to {@see CorsHandler} and forwarding to {@see Forwarder}. There is no CSRF check:
 * the proxy reads no session, so there is nothing to protect. The origin check stops other sites in
 * a browser, not a script, which can send any Origin. The path allow-list limits what any caller
 * can do with the API key.
 *
 * @see \MyParcelNL\Magento\Service\Proxy\ProxyConfig::HOSTS
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
    private CorsHandler $cors;

    public function __construct(
        RequestInterface $request,
        Forwarder $forwarder,
        CorsHandler $cors
    ) {
        $this->request   = $request;
        $this->forwarder = $forwarder;
        $this->cors      = $cors;
    }

    public function execute(): ResultInterface
    {
        $origin = $this->cors->getRequestOrigin($this->request);

        if ($this->cors->isPreflight($this->request)) {
            return $this->cors->buildPreflightResponse($this->request, $origin);
        }

        if (!$this->cors->isAllowedOrigin($origin)) {
            return $this->cors->buildForbidden();
        }

        $response = $this->forwarder->forward($this->request);
        return $this->cors->applyCorsHeaders($response, $origin);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
}
