<?php

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Module\Manager;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;

class SourceItem
{
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var Manager
     */
    private $moduleManager;

    /**
     * @var SourceItemRepositoryInterface
     */
    private $sourceItemRepository;

    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Manager $moduleManager
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->moduleManager         = $moduleManager;
        $this->setSourceItemRepositoryWhenInventoryApiEnabled();
    }

    /**
     * Retrieves links that are assigned to $stockId
     *
     * @param string $sku
     * @return SourceItemInterface[]|array
     */
    public function getSourceItemDetailBySKU(string $sku): array
    {
        if ($this->sourceItemRepository) {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter(SourceItemInterface::SKU, $sku)
                ->create();

            return $this->sourceItemRepository->getList($searchCriteria)->getItems();
        } 
        return [];        
    }

    /**
     * Check if the module Magento_InventoryApi is activated.
     * Some customers have removed the Magento_InventoryApi from their system.
     * That causes problems with the Multi Stock Inventory
     *
     * @return void
     */
    private function setSourceItemRepositoryWhenInventoryApiEnabled(): void
    {
        if ($this->moduleManager->isEnabled('Magento_InventoryApi')) {
            $this->sourceItemRepository = $this->objectManager->get(SourceItemRepositoryInterface::class);
        }
    }
}
