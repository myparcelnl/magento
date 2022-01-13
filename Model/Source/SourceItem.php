<?php

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\Sales\Model\Order\ShipmentFactory;

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

    /**
     * @var ShipmentFactory
     */
    private $shipmentFactory;

    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SourceItemRepositoryInterface $sourceItemRepository,
        ShipmentFactory $shipmentFactory
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sourceItemRepository = $sourceItemRepository;
        $this->shipmentFactory = $shipmentFactory;
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

    /**
     * Magento uses an afterCreate plugin on the shipmentFactory to set the SourceCode. In the default flow Magento
     * runs this code when you open the Create Shipment page. This behaviour doesn't occur in this flow, so we force
     * that flow to happen here.
     *
     * @param $order
     * @param $shipmentItems
     *
     */
    public function getSource($order, $shipmentItems)
    {
        $shipment = $this->shipmentFactory->create(
            $order,
            $shipmentItems
        );

        $extensionAttributes = $shipment->getExtensionAttributes();
//        var_dump($extensionAttributes->getSourceCode());
//        exit("\n|-------------\n" . __FILE__ . ':' . __LINE__ . "\n|-------------\n");
        return $extensionAttributes->getSourceCode();
    }
}
