<?php

namespace MyParcelBE\Magento\Controller\Adminhtml\Order;

use Magento\Framework\App\ResponseInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use MyParcelBE\Magento\Model\Sales\MagentoOrderCollection;

/**
 * Action to create and print MyParcel Track
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */
class CreateAndPrintMyParcelTrack extends \Magento\Framework\App\Action\Action
{
    const PATH_MODEL_ORDER = 'Magento\Sales\Model\Order';
    const PATH_URI_ORDER_INDEX = 'sales/order/index';

    /**
     * @var MagentoOrderCollection
     */
    private $orderCollection;

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->resultRedirectFactory = $context->getResultRedirectFactory();
        $this->orderCollection = new MagentoOrderCollection(
            $context->getObjectManager(),
            $this->getRequest(),
            null
        );
    }

    /**
     * Dispatch request
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        $this->massAction();

        return $this->resultRedirectFactory->create()->setPath(self::PATH_URI_ORDER_INDEX);
    }

    /**
     * Get selected items and process them
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     */
    private function massAction()
    {
        if ($this->orderCollection->apiKeyIsCorrect() !== true) {
            $message = 'You not have entered the correct API key. Go to the general settings in the back office of MyParcel BE to generate the API Key.';
            $this->messageManager->addErrorMessage(__($message));
            $this->_objectManager->get('Psr\Log\LoggerInterface')->critical($message);

            return $this;
        }

        if ($this->getRequest()->getParam('selected_ids')) {
            $orderIds = explode(',', $this->getRequest()->getParam('selected_ids'));
        } else {
            $orderIds = $this->getRequest()->getParam('selected');
        }

        if (empty($orderIds)) {
            throw new LocalizedException(__('No items selected'));
        }

        $this->getRequest()->setParams(['myparcel_track_email' => true]);

        $this->addOrdersToCollection($orderIds);

        $this->orderCollection
            ->setOptionsFromParameters()
            ->setNewMagentoShipment();

        if (!$this->orderCollection->hasShipment()) {
            $this->messageManager->addErrorMessage(__(MagentoOrderCollection::ERROR_ORDER_HAS_NO_SHIPMENT));
            return $this;
        }

        $this->orderCollection
            ->setMagentoTrack()
            ->setMyParcelTrack()
            ->createMyParcelConcepts()
            ->updateGridByOrder();

        if ($this->orderCollection->getOption('request_type') == 'concept') {
            return $this;
        }

        $this->orderCollection
            ->setPdfOfLabels()
            ->updateMagentoTrack()
            ->sendTrackEmails()
            ->downloadPdfOfLabels();

        return $this;
    }

    /**
     * @param $orderIds int[]
     */
    private function addOrdersToCollection($orderIds)
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
