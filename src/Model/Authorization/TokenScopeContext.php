<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Authorization;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

class TokenScopeContext implements ResetAfterRequestInterface
{
    /** @var array{scope: string, scopeId: int}|null */
    private ?array $owner = null;

    /** @var array<int, array{scope: string, scopeId: int, value: string}>|null Memoized full token rows. */
    private ?array $tokenRows = null;

    /** @var int[]|null Memoized result of permittedStoreIds(). */
    private ?array $permittedStoreIds = null;

    private CollectionFactory $configDataCollectionFactory;
    private StoreManagerInterface $storeManager;

    public function __construct(
        CollectionFactory $configDataCollectionFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->configDataCollectionFactory = $configDataCollectionFactory;
        $this->storeManager                = $storeManager;
    }

    public function setOwner(string $scope, int $scopeId): void
    {
        $this->owner             = ['scope' => $scope, 'scopeId' => $scopeId];
        $this->permittedStoreIds = null;
    }

    /**
     * @return array{scope: string, scopeId: int}|null
     */
    public function getOwner(): ?array
    {
        return $this->owner;
    }

    /**
     * Stores the token-authenticated caller may see.
     *
     * Returns null when no token authenticated this request (admin / Bearer / guest).
     * Otherwise returns the row-coordinate partition: each non-admin store's owner is
     * the most-specific row that exists (stores > websites > default), and a store
     * belongs to the caller iff its owner matches the caller's row coordinate.
     *
     * @return int[]|null
     */
    public function permittedStoreIds(): ?array
    {
        if ($this->owner === null) {
            return null;
        }

        if ($this->permittedStoreIds !== null) {
            return $this->permittedStoreIds;
        }

        $coords      = $this->coords();
        $ownerCoord  = $this->owner['scope'] . ':' . $this->owner['scopeId'];
        $permitted   = [];

        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId   = (int) $store->getId();
            $websiteId = (int) $store->getWebsiteId();

            $resolved = $this->resolveOwner($coords, $storeId, $websiteId);
            if ($resolved === $ownerCoord) {
                $permitted[] = $storeId;
            }
        }

        return $this->permittedStoreIds = $permitted;
    }

    /**
     * Finds the token row whose stored SHA-256 matches the presented plaintext (constant-time).
     * Memoizes the underlying row load so a token-authenticated request hits the DB once
     * across both findByHash() and permittedStoreIds().
     *
     * @return array{scope: string, scopeId: int}|null
     */
    public function findByHash(string $plaintext): ?array
    {
        $presentedHash = hash('sha256', $plaintext);

        foreach ($this->loadRows() as $row) {
            if (hash_equals($row['value'], $presentedHash)) {
                return ['scope' => $row['scope'], 'scopeId' => $row['scopeId']];
            }
        }

        return null;
    }

    /**
     * Throws NoSuchEntityException when a token-authenticated caller asks for a record
     * outside their permitted store set. No-op when no token authenticated this request.
     *
     * @throws NoSuchEntityException
     */
    public function assertStoreInScope(int $storeId): void
    {
        $permitted = $this->permittedStoreIds();
        if ($permitted === null) {
            return;
        }

        if (!in_array($storeId, $permitted, true)) {
            throw NoSuchEntityException::singleField('store_id', $storeId);
        }
    }

    public function _resetState(): void
    {
        $this->owner             = null;
        $this->tokenRows         = null;
        $this->permittedStoreIds = null;
    }

    /**
     * @return array<int, array{scope: string, scopeId: int, value: string}>
     */
    private function loadRows(): array
    {
        if ($this->tokenRows !== null) {
            return $this->tokenRows;
        }

        $collection = $this->configDataCollectionFactory->create()
            ->addFieldToFilter('path', TokenService::CONFIG_PATH)
            ->addFieldToFilter('scope', ['in' => [
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                ScopeInterface::SCOPE_WEBSITES,
                ScopeInterface::SCOPE_STORES,
            ]]);

        $rows = [];
        foreach ($collection->getItems() as $row) {
            $rows[] = [
                'scope'   => (string) $row->getData('scope'),
                'scopeId' => (int) $row->getData('scope_id'),
                'value'   => (string) $row->getData('value'),
            ];
        }

        return $this->tokenRows = $rows;
    }

    /**
     * @return array<string, true>
     */
    private function coords(): array
    {
        $coords = [];
        foreach ($this->loadRows() as $row) {
            $coords[$row['scope'] . ':' . $row['scopeId']] = true;
        }
        return $coords;
    }

    /**
     * @param array<string, true> $coords
     */
    private function resolveOwner(array $coords, int $storeId, int $websiteId): ?string
    {
        $candidate = ScopeInterface::SCOPE_STORES . ':' . $storeId;
        if (isset($coords[$candidate])) {
            return $candidate;
        }

        $candidate = ScopeInterface::SCOPE_WEBSITES . ':' . $websiteId;
        if (isset($coords[$candidate])) {
            return $candidate;
        }

        $candidate = ScopeConfigInterface::SCOPE_TYPE_DEFAULT . ':0';
        if (isset($coords[$candidate])) {
            return $candidate;
        }

        return null;
    }
}
