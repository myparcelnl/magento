<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Settings\Blueprint\ScopeBlueprints;

/**
 * The settings form of one admin scope, and the rows stored for it.
 *
 * The form comes from the scope's account, so two scopes with different api keys can offer different
 * fields. Its paths are also the save allow-list.
 */
class Settings
{
    private ScopeBlueprints   $scopeBlueprints;
    private CollectionFactory $scopeCollectionFactory;

    /** @var array<string,array<string,bool>> "scope|scopeId" => path => whether a row exists there */
    private array $rowsAtScope = [];

    public function __construct(
        ScopeBlueprints   $scopeBlueprints,
        CollectionFactory $scopeCollectionFactory
    )
    {
        $this->scopeBlueprints        = $scopeBlueprints;
        $this->scopeCollectionFactory = $scopeCollectionFactory;
    }

    /** @param string $scopeName 'default', 'websites', or 'stores' */
    public function getSections(string $scopeName, ?int $scopeId): array
    {
        return $this->scopeBlueprints->forScope($scopeName, $scopeId)->toArray()['sections'];
    }

    /** @return string[] every path the form offers at this scope */
    public function getAllFieldPaths(string $scopeName, ?int $scopeId): array
    {
        return $this->scopeBlueprints->forScope($scopeName, $scopeId)->paths();
    }

    /** True when the scope's capabilities could not be read, so the form shows no carrier. */
    public function isPermissive(string $scopeName, ?int $scopeId): bool
    {
        return $this->scopeBlueprints->forScope($scopeName, $scopeId)->isPermissive();
    }

    /**
     * Check if a field should be visible for the given scope.
     *
     * @param array  $field
     * @param string $scopeName 'default', 'websites', or 'stores'
     * @return bool
     */
    public function isFieldVisibleInScope(array $field, string $scopeName): bool
    {
        switch ($scopeName) {
            case ScopeConfigInterface::SCOPE_TYPE_DEFAULT:
                return $field['showInDefault'] ?? false;
            case ScopeInterface::SCOPE_WEBSITES:
                return $field['showInWebsite'] ?? false;
            case ScopeInterface::SCOPE_STORES:
                return $field['showInStore'] ?? false;
            default:
                return false;
        }
    }

    /**
     * Resolve the admin's current scope from request params.
     *
     * @return array{0: string, 1: int} [scopeName, scopeId]
     */
    public function getCurrentScopeFromRequest(RequestInterface $request): array
    {
        if (($storeId = $request->getParam('store'))) {
            return [ScopeInterface::SCOPE_STORES, (int) $storeId];
        }
        if (($websiteId = $request->getParam('website'))) {
            return [ScopeInterface::SCOPE_WEBSITES, (int) $websiteId];
        }
        return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
    }

    /**
     * Partition-aware: true if and only if a row exists at the exact (scope, scopeId) for this path.
     * Unlike hasOwnValue() this does NOT short-circuit for default scope.
     *
     * Answered from one read of every path the scope's form offers, warmed on the first ask per
     * scope: the form asks once per field and there are hundreds of them, so a COUNT each cost the
     * page a query per field at website and store scope. A path the form does not offer still costs
     * its own.
     */
    public function hasRowAtScope(string $path, string $scope, int $scopeId): bool
    {
        $key = $scope . '|' . $scopeId;

        if (! isset($this->rowsAtScope[$key])) {
            $paths  = $this->getAllFieldPaths($scope, $scopeId);
            $stored = $this->storedValuesAtScope($paths, $scope, $scopeId);
            $known  = [];

            foreach ($paths as $declared) {
                $known[$declared] = array_key_exists($declared, $stored);
            }

            $this->rowsAtScope[$key] = $known;
        }

        // isset() is safe here: a warmed path is true or false, never null.
        if (isset($this->rowsAtScope[$key][$path])) {
            return $this->rowsAtScope[$key][$path];
        }

        $collection = $this->scopeCollectionFactory->create()
            ->addFieldToFilter('path', $path)
            ->addFieldToFilter('scope', $scope)
            ->addFieldToFilter('scope_id', $scopeId);

        return $collection->getSize() > 0;
    }

    /**
     * The values stored at exactly this (scope, scopeId), keyed by path.
     *
     * Row coordinates, never inheritance: a path missing from the result has no row here, which is
     * not the same as having no value. A save compares against this to skip fields nobody changed,
     * and a cascaded read would answer "unchanged" for a scope that is only inheriting — which is
     * precisely the case that has to be written.
     *
     * One query for the whole form, so the comparison costs less than the writes it avoids.
     *
     * @param  string[] $paths
     * @return array<string, string|null>
     */
    public function storedValuesAtScope(array $paths, string $scope, int $scopeId): array
    {
        if ([] === $paths) {
            return [];
        }

        $collection = $this->scopeCollectionFactory->create()
            ->addFieldToFilter('path', ['in' => array_values(array_unique($paths))])
            ->addFieldToFilter('scope', $scope)
            ->addFieldToFilter('scope_id', $scopeId);

        $values = [];

        foreach ($collection as $row) {
            $values[(string) $row->getData('path')] = $row->getData('value');
        }

        return $values;
    }

    /**
     * Inheritance-aware: default scope always "owns" its value (config.xml fallback),
     * otherwise true if and only if an override row exists at the exact (scope, scopeId).
     */
    public function hasOwnValue(string $path, string $scopeName = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ?int $scopeId = null): bool
    {
        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scopeName) {
            return true;
        }

        return $this->hasRowAtScope($path, $scopeName, (int) $scopeId);
    }
}
