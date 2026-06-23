<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ApiAccessToken;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Store\Model\ScopeInterface;

/**
 * Generates, rotates, revokes, and looks up MyParcel API access tokens.
 *
 * Persists SHA-256 hashes in core_config_data at myparcelnl_magento_general/api_access_token.
 * Plaintext is returned once at generation and never re-readable. Hash uniqueness is
 * enforced across the (scope, scopeId) coordinate space — colliding hashes raise
 * AlreadyExistsException. Allowed scopes: default / websites / stores.
 */
class TokenService
{
    public const CONFIG_PATH = 'myparcelnl_magento_general/api_access_token';

    private const CONFIG_CACHE_TYPE = 'config';

    private const ALLOWED_SCOPES = [
        ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        ScopeInterface::SCOPE_WEBSITES,
        ScopeInterface::SCOPE_STORES,
    ];

    private WriterInterface               $configWriter;
    private CollectionFactory             $configDataCollectionFactory;
    private TypeListInterface             $cacheTypeList;
    private RandomBytesGeneratorInterface $randomBytes;

    public function __construct(
        WriterInterface               $configWriter,
        CollectionFactory             $configDataCollectionFactory,
        TypeListInterface             $cacheTypeList,
        RandomBytesGeneratorInterface $randomBytes
    ) {
        $this->configWriter                = $configWriter;
        $this->configDataCollectionFactory = $configDataCollectionFactory;
        $this->cacheTypeList               = $cacheTypeList;
        $this->randomBytes                 = $randomBytes;
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Generates a fresh plaintext token for ($scope, $scopeId), stores its SHA-256 hash, returns the plaintext.
     *
     * @throws InputException         when $scope is not one of default, websites, stores.
     * @throws AlreadyExistsException when the produced hash already exists at a different (scope, scopeId).
     */
    public function generateForScope(string $scope, int $scopeId): string
    {
        [$scope, $scopeId] = $this->normalizeCoordinate($scope, $scopeId);

        $plaintext = bin2hex($this->randomBytes->generate(32));
        $hash      = self::hashToken($plaintext);

        $this->assertHashIsUnique($hash, $scope, $scopeId);

        $this->configWriter->save(self::CONFIG_PATH, $hash, $scope, $scopeId);
        $this->cacheTypeList->cleanType(self::CONFIG_CACHE_TYPE);

        return $plaintext;
    }

    /**
     * Clears the token row at ($scope, $scopeId). Idempotent — deleting a non-existent row is a no-op.
     *
     * @throws InputException when $scope is not one of default, websites, stores.
     */
    public function revokeForScope(string $scope, int $scopeId): void
    {
        [$scope, $scopeId] = $this->normalizeCoordinate($scope, $scopeId);

        $this->configWriter->delete(self::CONFIG_PATH, $scope, $scopeId);
        $this->cacheTypeList->cleanType(self::CONFIG_CACHE_TYPE);
    }

    /**
     * @return array{0: string, 1: int}
     * @throws InputException
     */
    private function normalizeCoordinate(string $scope, int $scopeId): array
    {
        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new InputException(__('Unsupported scope "%1".', $scope));
        }

        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT !== $scope && $scopeId <= 0) {
            throw new InputException(__('scopeId must be a positive integer for scope "%1".', $scope));
        }

        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scope) {
            $scopeId = 0;
        }

        return [$scope, $scopeId];
    }

    /**
     * @throws AlreadyExistsException
     */
    private function assertHashIsUnique(string $hash, string $scope, int $scopeId): void
    {
        $collection = $this->configDataCollectionFactory->create()
            ->addFieldToFilter('path', self::CONFIG_PATH)
            ->addFieldToFilter('value', $hash);

        foreach ($collection->getItems() as $row) {
            if ($row->getData('scope') === $scope && (int) $row->getData('scope_id') === $scopeId) {
                continue;
            }
            throw new AlreadyExistsException(__('Generated token hash already exists at another scope.'));
        }
    }
}
