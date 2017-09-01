<?php

namespace MyParcelNL\Magento\Controller\Adminhtml\Order;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResponseInterface;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;

/**
 * Action to send mails with a return label
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
class SendMyParcelReturnMail extends \Magento\Framework\App\Action\Action
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
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function execute()
    {
        $this->sendReturnMail();

        return $this->resultRedirectFactory->create()->setPath(self::PATH_URI_ORDER_INDEX);
    }

    /**
     * Get selected items and process them
     *
     * @return $this
     * @throws LocalizedException
     */
    private function sendReturnMail()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        if ($this->orderCollection->apiKeyIsCorrect() !== true) {
            $message = 'You not have entered the correct API key. To get your personal API credentials you should contact MyParcel.';
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

        $this->addOrdersToCollection($orderIds);

        if (!$this->orderCollection->hasShipment()) {
            $this->messageManager->addErrorMessage(__(MagentoOrderCollection::ERROR_ORDER_HAS_NO_SHIPMENT));
            return $this;
        }

        try {
            $this->orderCollection
                ->setMyParcelTrack()
                ->setLatestData()
                ->sendReturnLabelMails();
        } catch (\Exception $e) {
            if (count($this->messageManager->getMessages()) == 0) {
                $this->messageManager->addErrorMessage(__('An error has occurred while sending mails with a return label. Please contact MyParcel.'));
                $this->_objectManager->get('Psr\Log\LoggerInterface')->critical($e);
            }

            return $this;
        }

        $message = 'Return label mail is send to customer.';
        $this->messageManager->addSuccessMessage(__($message));

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
