<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Authorization;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Integration\Api\IntegrationServiceInterface;

/**
 * UserContextInterface for MyParcel-token-authenticated REST requests.
 *
 * Reads the `Authorization: myparcel <token>` header (with a REDIRECT_HTTP_AUTHORIZATION
 * fallback for installs whose DocumentRoot is the Magento root rather than pub/), matches
 * the plaintext against stored SHA-256 hashes via {@see TokenScopeContext}, and on success
 * exposes user type USER_TYPE_INTEGRATION resolved against the "MyParcel API" integration.
 * Bearer / OAuth / admin-session / guest requests pass through untouched.
 */
class ApiAccessTokenUserContext implements UserContextInterface
{
    public const INTEGRATION_NAME = 'MyParcel API';
    private const SCHEME          = 'myparcel';

    private RequestInterface $request;
    private IntegrationServiceInterface $integrationService;
    private TokenScopeContext $tokenScopeContext;

    private bool $processed = false;
    private ?int $userId    = null;
    private ?int $userType  = null;

    public function __construct(
        RequestInterface $request,
        IntegrationServiceInterface $integrationService,
        TokenScopeContext $tokenScopeContext
    ) {
        $this->request            = $request;
        $this->integrationService = $integrationService;
        $this->tokenScopeContext  = $tokenScopeContext;
    }

    public function getUserId()
    {
        $this->processRequest();
        return $this->userId;
    }

    public function getUserType()
    {
        $this->processRequest();
        return $this->userType;
    }

    private function processRequest(): void
    {
        if ($this->processed) {
            return;
        }
        $this->processed = true;

        $token = $this->extractToken();
        if ($token === null) {
            return;
        }

        $matched = $this->tokenScopeContext->findByHash($token);
        if ($matched === null) {
            return;
        }

        $integrationId = $this->resolveIntegrationId();
        if ($integrationId === null) {
            return;
        }

        $this->tokenScopeContext->setOwner($matched['scope'], $matched['scopeId']);
        $this->userType = UserContextInterface::USER_TYPE_INTEGRATION;
        $this->userId   = $integrationId;
    }

    /**
     * Returns the plaintext token if the Authorization header carries our scheme,
     * otherwise null. Scheme match is case-insensitive; native Bearer is left untouched.
     */
    private function extractToken(): ?string
    {
        $header = $this->readAuthorizationHeader();
        if ($header === null) {
            return null;
        }

        $parts = explode(' ', $header, 2);
        if (count($parts) !== 2) {
            return null;
        }

        if (strtolower($parts[0]) !== self::SCHEME) {
            return null;
        }

        $token = trim($parts[1]);
        return $token === '' ? null : $token;
    }

    /**
     * Apache prefixes every env var with REDIRECT_ on each internal redirect.
     * On installs whose DocumentRoot is the Magento root (not pub/), the
     * default .htaccess redirects /<path> -> /pub/<path>, after which the
     * Authorization header lives in $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
     * and Magento's Request::getHeader() can no longer see it.
     */
    private function readAuthorizationHeader(): ?string
    {
        $header = $this->request->getHeader('Authorization');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }

        return null;
    }

    private function resolveIntegrationId(): ?int
    {
        $integration = $this->integrationService->findByName(self::INTEGRATION_NAME);
        $id          = $integration->getId();

        return $id ? (int) $id : null;
    }
}
