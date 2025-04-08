<?php

namespace MyParcelNL\Magento\Controller\Adminhtml\Shipment;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;

/**
 * Action to create and print MyParcel Track
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
class CreateAndPrintMyParcelTrack extends Action
{
    const PATH_URI_SHIPMENT_INDEX = 'sales/shipment/index';

    /**
     * @var MagentoShipmentCollection
     */
    private MagentoShipmentCollection $shipmentCollection;

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->resultRedirectFactory = $context->getResultRedirectFactory();
        $this->shipmentCollection    = new MagentoShipmentCollection(
            $context->getObjectManager(),
            $this->getRequest(),
            null
        );
    }

    /**
     * Dispatch request
     *
     * @return ResultInterface|ResponseInterface
     * @throws LocalizedException
     * @throws ApiException
     * @throws MissingFieldException
     */
    public function execute()
    {
        file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log',"EXECUTE (Shipment/CreateAndPrint)\n", FILE_APPEND);
        $this->massAction();

        return $this->resultRedirectFactory->create()->setPath(self::PATH_URI_SHIPMENT_INDEX);
    }

    /**
     * Get selected items and process them
     *
     * @return void
     * @throws LocalizedException
     * @throws ApiException
     * @throws MissingFieldException
     * @throws \Exception
     */
    private function massAction(): void
    {
        if ($this->getRequest()->getParam('selected_ids')) {
            $shipmentIds = explode(',', $this->getRequest()->getParam('selected_ids'));
        } else {
            $shipmentIds = $this->getRequest()->getParam('selected');
        }

        if (empty($shipmentIds)) {
            throw new LocalizedException(__('No items selected'));
        }

        $this->getRequest()->setParams(['myparcel_track_email' => true]);

        $this->addShipmentsToCollection($shipmentIds);

        $this->shipmentCollection
            ->setOptionsFromParameters()
        ;

        $this->shipmentCollection
            ->syncMagentoToMyparcel()
            ->setMagentoTrack()
            ->setNewMyParcelTracks()
            ->createMyParcelConcepts()
            ->updateMagentoTrack()
        ;

        if (TrackAndTrace::VALUE_CONCEPT === $this->shipmentCollection->getOption('request_type')) {
            return;
        }

        $this->shipmentCollection
            ->addReturnShipments()
            ->setPdfOfLabels()
            ->updateMagentoTrack()
            ->downloadPdfOfLabels()
        ;
    }

    /**
     * @param int[] $shipmentIds
     */
    private function addShipmentsToCollection($shipmentIds): void
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoCollection::PATH_MODEL_SHIPMENT_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $shipmentIds]);
        $this->shipmentCollection->setShipmentCollection($collection);
    }
}
