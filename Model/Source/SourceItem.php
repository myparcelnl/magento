<?php

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;

class SourceItem
{
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var SourceItemRepositoryInterface
     */
    private $sourceItemRepository;

    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SourceItemRepositoryInterface $sourceItemRepository
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sourceItemRepository = $sourceItemRepository;
    }

    /**
     * Retrieves links that are assigned to $stockId
     *
     * @param string $sku
     * @return SourceItemInterface[]
     */
    public function getSourceItemDetailBySKU(string $sku): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(SourceItemInterface::SKU, $sku)
            ->create();

        return $this->sourceItemRepository->getList($searchCriteria)->getItems();
    }
}
