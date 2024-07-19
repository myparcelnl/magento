<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use MyParcelBE\Magento\Model\Sales\MagentoCollection;
use MyParcelBE\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelBE\Magento\Model\Sales\TrackTraceHolder;
use MyParcelBE\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Exception\ApiException;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Helper\ValidatePostalCode;
use MyParcelNL\Sdk\src\Helper\ValidateStreet;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use Magento\Sales\Model\Order;

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
class CreateAndPrintMyParcelTrack extends \Magento\Framework\App\Action\Action
{
    const PATH_MODEL_ORDER     = 'Magento\Sales\Model\Order';
    const PATH_URI_ORDER_INDEX = 'sales/order/index';

    /**
     * @var \Magento\Backend\App\Action\Context
     */
    private $context;

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
        $this->context = $context;
        parent::__construct($this->context);

        $this->resultRedirectFactory = $this->context->getResultRedirectFactory();
        $this->orderCollection       = new MagentoOrderCollection(
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
        try {
            $this->massAction();
        } catch (ApiException | MissingFieldException $e) {
            $this->_objectManager->get('Psr\Log\LoggerInterface')->critical($e);
        }

        return $this->resultRedirectFactory->create()->setPath(self::PATH_URI_ORDER_INDEX);
    }

    /**
     * Get selected items and process them
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
     */
    private function massAction()
    {
        if (! $this->orderCollection->apiKeyIsCorrect()) {
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

        $this->getRequest()->setParams(['myparcel_track_email' => true]);

        $orderIds = $this->filterCorrectAddress($orderIds);
        $this->addOrdersToCollection($orderIds);

        if (TrackTraceHolder::EXPORT_MODE_PPS === $this->orderCollection->getExportMode()) {
            $this->orderCollection->setFulfilment();

            return $this;
        }

        $this->orderCollection->setOptionsFromParameters()
            ->setNewMagentoShipment();

        $this->orderCollection->reload();

        if (! $this->orderCollection->hasShipment()) {
            $this->messageManager->addErrorMessage(__(MagentoCollection::ERROR_ORDER_HAS_NO_SHIPMENT));
        }

        if ($this->messageManager->getMessages()->getErrors()) {
            $this->messageManager->getMessages();

            return $this;
        }

        $this->orderCollection->syncMagentoToMyparcel()
            ->setMagentoTrack()
            ->setNewMyParcelTracks()
            ->createMyParcelConcepts()
            ->updateMagentoTrack();

        if (TrackAndTrace::VALUE_CONCEPT === $this->orderCollection->getOption('request_type')
            || $this->orderCollection->myParcelCollection->isEmpty()) {
            return $this;
        }
        $this->orderCollection->addReturnShipments()
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

    /**
     * @param array $orderIds
     *
     * @return array
     */
    private function filterCorrectAddress(array $orderIds): array
    {
        $objectManager = ObjectManager::getInstance();
        $order         = $objectManager->get(Order::class);
        // Go through the selected orders and check if the address details are correct
        foreach ($orderIds as $orderId) {
            $order->load($orderId);

            $fullStreet         = implode(" ", $order->getShippingAddress()->getStreet() ?? []);
            $postcode           = preg_replace('/\s+/', '', $order->getShippingAddress()->getPostcode());
            $destinationCountry = $order->getShippingAddress()->getCountryId();
            $keyOrderId         = array_search($orderId, $orderIds, true);

            // Validate the street and house number. If the address is wrong then get the orderId from the array and delete it.
            if (! ValidateStreet::validate($fullStreet, AbstractConsignment::CC_NL, $destinationCountry)) {
                $errorHuman = 'An error has occurred while validating the order number ' . $order->getIncrementId() . '. Check street.';
                $this->messageManager->addErrorMessage($errorHuman);

                unset($orderIds[$keyOrderId]);
            }
            // Validate the postcode. If the postcode is wrong then get the orderId from the array and delete it.
            if (! ValidatePostalCode::validate($postcode, $destinationCountry)) {
                $errorHuman = 'An error has occurred while validating the order number ' . $order->getIncrementId() . '. Check postcode.';
                $this->messageManager->addErrorMessage($errorHuman);

                unset($orderIds[$keyOrderId]);
            }
        }

        return $orderIds;
    }
}
