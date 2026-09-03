<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Shipment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

/**
 * The shipment grid's export: creates the MyParcel shipments for the selected shipments.
 *
 * The order grid's controller of the same name does the same for orders.
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */
class CreateAndPrintMyParcelTrack extends LabelExportAction
{
    private MagentoShipmentCollection $shipmentCollection;

    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->shipmentCollection = new MagentoShipmentCollection(
            $context->getObjectManager(),
            $this->getRequest(),
            null
        );
    }

    /**
     * @throws LocalizedException
     */
    protected function massAction(): ?array
    {
        $shipmentIds = $this->selectedIds();

        $this->getRequest()->setParams(['myparcel_track_email' => true]);

        $this->addShipmentsToCollection($shipmentIds);

        $this->shipmentCollection
            ->setOptionsFromParameters()
        ;

        $this->shipmentCollection
            ->setMagentoTrack()
            ->setNewMyParcelTracks()
            ->createMyParcelConcepts()
            ->updateMagentoTrack()
        ;

        if (TrackAndTrace::VALUE_CONCEPT === $this->shipmentCollection->getOption('request_type')) {
            return null;
        }

        $this->shipmentCollection->addReturnShipments();

        return $this->labelsFor($this->shipmentCollection, false);
    }

    /**
     * @param string[] $shipmentIds
     */
    private function addShipmentsToCollection(array $shipmentIds): void
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoCollection::PATH_MODEL_SHIPMENT_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $shipmentIds]);
        $this->shipmentCollection->setShipmentCollection($collection);
    }
}
