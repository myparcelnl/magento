<?php

namespace MyParcelNL\Magento\Controller\Adminhtml\Shipment;

use Magento\Framework\App\ResponseInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;

/**
 * Action to create and print MyParcel Track
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */
class CreateAndPrintMyParcelTrack extends \Magento\Framework\App\Action\Action
{
    const PATH_MODEL_ORDER = 'Magento\Sales\Model\Order';
    const PATH_URI_SHIPMENT_INDEX = 'sales/shipment/index';

    /**
     * @var MagentoShipmentCollection
     */
    private $shipmentCollection;

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->resultRedirectFactory = $context->getResultRedirectFactory();
        $this->shipmentCollection = new MagentoShipmentCollection(
            $context->getObjectManager(),
            $this->getRequest(),
            null
        );
    }

    /**
     * Dispatch request
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function execute()
    {
        $this->massAction();

        return $this->resultRedirectFactory->create()->setPath(self::PATH_URI_SHIPMENT_INDEX);
    }

    /**
     * Get selected items and process them
     *
     * @return $this
     * @throws LocalizedException
     */
    private function massAction()
    {
        if ($this->shipmentCollection->apiKeyIsCorrect() !== true) {
            $message = 'You not have entered the correct API key. To get your personal API credentials you should contact MyParcel.';
            $this->messageManager->addErrorMessage(__($message));
            $this->_objectManager->get('Psr\Log\LoggerInterface')->critical($message);

            return $this;
        }

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

        try {
            $this->shipmentCollection
                ->setOptionsFromParameters()
                ->setMagentoTrack()
                ->setMyParcelTrack()
                ->createMyParcelConcepts()
                ->updateGridByShipment();

            if ($this->shipmentCollection->getOption('request_type') == 'concept') {
                return $this;
            }

            $this->shipmentCollection
                ->setPdfOfLabels()
                ->updateMagentoTrack()
                ->sendTrackEmailFromShipments()
                ->downloadPdfOfLabels();

        } catch (\Exception $e) {
            if (count($this->messageManager->getMessages()) == 0) {
                $this->messageManager->addErrorMessage(__('An error has occurred while creating a MyParcel label. You may not have entered the correct API key. To get your personal API credentials you should contact MyParcel.'));
                $this->_objectManager->get('Psr\Log\LoggerInterface')->critical($e);
            }
        }

        return $this;
    }

    /**
     * @param $shipmentIds int[]
     */
    private function addShipmentsToCollection($shipmentIds)
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\order\shipment\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoShipmentCollection::PATH_MODEL_SHIPMENT);
        $collection->addAttributeToFilter('entity_id', ['in' => $shipmentIds]);
        $this->shipmentCollection->setShipmentCollection($collection);
    }
}
