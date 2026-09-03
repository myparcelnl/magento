<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

/**
 * The order grid's export: creates the MyParcel shipments for the selected orders.
 *
 * Track & trace emails are not sent here: a barcode only exists once the label request has run, so
 * PrintMyParcelLabels sends them — this controller asks for that with the notify flag.
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
    private MagentoOrderCollection $orderCollection;
    private Config                 $config;

    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->config          = $this->_objectManager->get(Config::class);
        $this->orderCollection = new MagentoOrderCollection(
            $this->_objectManager,
            $this->getRequest(),
            null
        );
    }

    /**
     * @throws LocalizedException
     */
    protected function massAction(): ?array
    {
        $orderIds = $this->selectedIds();

        $this->getRequest()->setParams(['myparcel_track_email' => true]);

        $this->addOrdersToCollection($orderIds);

        if (Config::EXPORT_MODE_PPS === $this->config->getExportMode()) {
            $this->orderCollection->setFulfilment();

            return null;
        }

        $this->orderCollection->setOptionsFromParameters()
                              ->setNewMagentoShipment()
        ;

        $this->orderCollection->reload();

        if (! $this->orderCollection->hasShipment()) {
            $this->messageManager->addErrorMessage(__(MagentoCollection::ERROR_ORDER_HAS_NO_SHIPMENT));

            return null;
        }

        $this->orderCollection->setMagentoTrack()
                              ->setNewMyParcelTracks()
                              ->createMyParcelConcepts()
                              ->updateMagentoTrack()
        ;

        // Only a concept request stops here. Asking whether anything was *built* would skip a
        // selection of orders that already carry labels, which is a reprint rather than nothing to
        // do — the labels below come from stored shipment ids, not from this run.
        if (TrackAndTrace::VALUE_CONCEPT === $this->orderCollection->getOption('request_type')) {
            return null;
        }

        $this->orderCollection->addReturnShipments();

        return $this->labelsFor($this->orderCollection, true);
    }

    /**
     * @param string[] $orderIds
     */
    private function addOrdersToCollection(array $orderIds): void
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
