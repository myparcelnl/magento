<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ApiAccessToken;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Store\Model\ScopeInterface;

class TokenService
{
    public const CONFIG_PATH = 'myparcelnl_magento_general/api_access_token';

    private const ALLOWED_SCOPES = [
        ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        ScopeInterface::SCOPE_WEBSITES,
        ScopeInterface::SCOPE_STORES,
    ];

    private WriterInterface   $configWriter;
    private CollectionFactory $configDataCollectionFactory;

    public function __construct(
        WriterInterface   $configWriter,
        CollectionFactory $configDataCollectionFactory
    ) {
        $this->configWriter                = $configWriter;
        $this->configDataCollectionFactory = $configDataCollectionFactory;
    }

    /**
     * Generates a fresh plaintext token for ($scope, $scopeId), stores its SHA-256 hash, returns the plaintext.
     *
     * @throws InputException         when $scope is not one of default, websites, stores.
     * @throws AlreadyExistsException when the produced hash already exists at a different (scope, scopeId).
     */
    public function generateForScope(string $scope, int $scopeId): string
    {
        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new InputException(__('Unsupported scope "%1".', $scope));
        }

        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scope) {
            $scopeId = 0;
        }

        $plaintext = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);

        $this->assertHashIsUnique($hash, $scope, $scopeId);

        $this->configWriter->save(self::CONFIG_PATH, $hash, $scope, $scopeId);

        return $plaintext;
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
