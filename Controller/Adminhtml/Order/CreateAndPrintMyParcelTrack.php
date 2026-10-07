<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
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
    public const ERROR_MIXED_ORDER_V1 = 'This selection has orders of accounts that export the entire order and orders of other accounts. Select one kind of order and try again.';

    private MagentoOrderCollection $orderCollection;
    private StoredAccount          $storedAccount;

    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->storedAccount   = $this->_objectManager->get(StoredAccount::class);
        $this->orderCollection = new MagentoOrderCollection(
            $this->_objectManager,
            $this->getRequest()
        );
    }

    /**
     * @throws LocalizedException
     */
    protected function massAction(): ?array
    {
        $orderIds = $this->selectedIds();

        $this->getRequest()->setParams(['myparcel_track_email' => true]);

        $orderV1 = $this->orderV1Of($this->addOrdersToCollection($orderIds));

        if (null === $orderV1) {
            $this->messageManager->addErrorMessage(__(self::ERROR_MIXED_ORDER_V1));

            return null;
        }

        $this->orderCollection->setOptionsFromParameters();

        if ($orderV1) {
            $this->orderCollection->setFulfilment();

            return null;
        }

        $this->orderCollection->setNewMagentoShipment();

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

        return $this->labelsFor($this->orderCollection, true);
    }

    /**
     * @param string[] $orderIds
     */
    private function addOrdersToCollection(array $orderIds): OrderCollection
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
        $this->orderCollection->setOrderCollection($collection);

        return $collection;
    }

    /** Whether every selected order's account has order v1; null when the selection mixes both. */
    private function orderV1Of(OrderCollection $orders): ?bool
    {
        $modes = [];

        foreach (array_unique($orders->getColumnValues('store_id')) as $storeId) {
            $modes[] = $this->storedAccount->hasOrderV1ForStore((int) $storeId);
        }

        $modes = array_unique($modes);

        return 1 < count($modes) ? null : (bool) reset($modes);
    }
}
