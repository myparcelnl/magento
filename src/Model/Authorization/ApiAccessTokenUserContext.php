<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Authorization;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

class ApiAccessTokenUserContext implements UserContextInterface
{
    public const INTEGRATION_NAME = 'MyParcel API';
    private const SCHEME          = 'myparcel';

    private RequestInterface $request;
    private CollectionFactory $configDataCollectionFactory;
    private IntegrationServiceInterface $integrationService;
    private TokenScopeContext $tokenScopeContext;

    private bool $processed = false;
    private ?int $userId    = null;
    private ?int $userType  = null;

    public function __construct(
        RequestInterface $request,
        CollectionFactory $configDataCollectionFactory,
        IntegrationServiceInterface $integrationService,
        TokenScopeContext $tokenScopeContext
    ) {
        $this->request                     = $request;
        $this->configDataCollectionFactory = $configDataCollectionFactory;
        $this->integrationService          = $integrationService;
        $this->tokenScopeContext           = $tokenScopeContext;
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

        $matched = $this->findMatchingRow($token);
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
        $header = $this->request->getHeader('Authorization');
        if (!is_string($header) || $header === '') {
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
     * @return array{scope: string, scopeId: int}|null
     */
    private function findMatchingRow(string $plaintext): ?array
    {
        $presentedHash = hash('sha256', $plaintext);

        $collection = $this->configDataCollectionFactory->create()
            ->addFieldToFilter('path', TokenService::CONFIG_PATH)
            ->addFieldToFilter('scope', ['in' => [
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                ScopeInterface::SCOPE_WEBSITES,
                ScopeInterface::SCOPE_STORES,
            ]]);

        foreach ($collection->getItems() as $row) {
            $stored = (string) $row->getData('value');
            if (hash_equals($stored, $presentedHash)) {
                return [
                    'scope'   => (string) $row->getData('scope'),
                    'scopeId' => (int) $row->getData('scope_id'),
                ];
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
