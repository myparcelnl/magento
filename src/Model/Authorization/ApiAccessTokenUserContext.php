<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Authorization;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

/**
 * User context for REST requests that send `Authorization: myparcel <token>`.
 *
 * A match makes the caller the "MyParcel API" integration. Other requests pass through untouched.
 * Keep ResetAfterRequestInterface in lockstep with {@see TokenScopeContext}: a reused singleton
 * otherwise keeps the identity while the scope owner is null, which disables every store-scope filter.
 */
class ApiAccessTokenUserContext implements UserContextInterface, ResetAfterRequestInterface
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

    public function _resetState(): void
    {
        $this->processed = false;
        $this->userId    = null;
        $this->userType  = null;
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

        $hash = TokenService::hashToken($token);
        unset($token);

        $matched = $this->tokenScopeContext->findByHash($hash);
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

    /** Get the plaintext token when the header uses our scheme (case-insensitive), else null. */
    private function extractToken(): ?string
    {
        $header = $this->readAuthorizationHeader();
        if ($header === null) {
            return null;
        }

        return preg_match('/^' . self::SCHEME . ' ([a-z0-9]{64})$/i', $header, $m) ? $m[1] : null;
    }

    /**
     * Get the Authorization header, also after an Apache internal redirect.
     *
     * When the DocumentRoot is the Magento root, .htaccess redirects to pub/ and the header moves
     * to REDIRECT_HTTP_AUTHORIZATION, which Request::getHeader() cannot see.
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
