<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Proxy;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;

/**
 * Owns the storefront CORS lifecycle for the proxy.
 *
 * - Detects preflight requests (`OPTIONS` + `Access-Control-Request-Method`).
 * - Decides whether an `Origin` (or `Referer` fallback) is permitted by
 *   comparing scheme/host/port exactly against every store's web base URL.
 * - Builds the 204 preflight response with `Access-Control-Allow-*` and
 *   `Vary` headers, or a 403 `application/problem+json` if the origin is
 *   not on the allow-list.
 * - Applies `Access-Control-Allow-Origin` and `Vary: Origin` to forwarded
 *   responses, or replaces the response with a 403 `application/problem+json`
 *   if the origin is not on the allow-list.
 * - Provides {@see buildForbidden()} as the single source of truth for the
 *   "origin not allowed" 403 response shape (RFC 9457).
 *
 * The methods that emit `Access-Control-Allow-Origin` validate the origin
 * themselves so they can never echo an unvalidated value, regardless of
 * caller — defense in depth on top of the controller's pre-forward gate.
 *
 * `Access-Control-Allow-Credentials` is intentionally not emitted — the
 * {@see Client} already strips inbound `Authorization` and `Cookie`.
 * This is the real authorization policy for the proxy; the controller's
 * `validateForCsrf` is permissive.
 */
class CorsHandler
{
    private const PREFLIGHT_MAX_AGE_SECONDS = 600;
    private const DEFAULT_ALLOWED_HEADERS   = 'Content-Type, Accept, Accept-Language';

    private StoreManagerInterface $storeManager;
    private RawFactory $rawFactory;

    public function __construct(StoreManagerInterface $storeManager, RawFactory $rawFactory)
    {
        $this->storeManager = $storeManager;
        $this->rawFactory   = $rawFactory;
    }

    public function isPreflight(RequestInterface $request): bool
    {
        if (strtoupper((string) $request->getMethod()) !== 'OPTIONS') {
            return false;
        }
        return (string) ($request->getHeader('Access-Control-Request-Method') ?: '') !== '';
    }

    public function getRequestOrigin(RequestInterface $request): string
    {
        $origin = (string) ($request->getHeader('Origin') ?: '');
        if ($origin !== '') {
            return $origin;
        }
        return (string) ($request->getHeader('Referer') ?: '');
    }

    public function isAllowedOrigin(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }
        foreach ($this->storeManager->getStores() as $store) {
            $baseUrl = (string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
            if ($this->sameSchemeHostPort($origin, $baseUrl)) {
                return true;
            }
        }
        return false;
    }

    public function buildPreflightResponse(RequestInterface $request, string $origin): Raw
    {
        if (!$this->isAllowedOrigin($origin)) {
            return $this->buildForbidden();
        }

        $requested      = (string) ($request->getHeader('Access-Control-Request-Headers') ?: '');
        $allowedHeaders = $requested !== '' ? $requested : self::DEFAULT_ALLOWED_HEADERS;

        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(204);
        $result->setHeader('Access-Control-Allow-Origin', $origin, true);
        $result->setHeader('Access-Control-Allow-Methods', implode(', ', Client::ALLOWED_METHODS), true);
        $result->setHeader('Access-Control-Allow-Headers', $allowedHeaders, true);
        $result->setHeader('Access-Control-Max-Age', (string) self::PREFLIGHT_MAX_AGE_SECONDS, true);
        $result->setHeader(
            'Vary',
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            true
        );
        $result->setContents('');
        return $result;
    }

    public function applyCorsHeaders(Raw $response, string $origin): ResultInterface
    {
        if (!$this->isAllowedOrigin($origin)) {
            return $this->buildForbidden();
        }
        $response->setHeader('Access-Control-Allow-Origin', $origin, true);
        // Append to any Vary header forwarded from upstream; multiple Vary lines
        // are equivalent to a single comma-separated one (RFC 7230 §3.2.2).
        $response->setHeader('Vary', 'Origin', false);
        return $response;
    }

    public function buildForbidden(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(403);
        $result->setHeader('Content-Type', ProblemDetails::CONTENT_TYPE, true);
        $result->setContents(ProblemDetails::fromStatus(403, 'origin not allowed')->toJsonString());
        return $result;
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
}
