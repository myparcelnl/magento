<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Helper\ValidatePostalCode;
use MyParcelNL\Sdk\Helper\ValidateStreet;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

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
class CreateAndPrintMyParcelTrack extends \Magento\Backend\App\Action
{
    const PATH_MODEL_ORDER     = 'Magento\Sales\Model\Order';
    const PATH_URI_ORDER_INDEX = 'sales/order/index';


    private MagentoOrderCollection $orderCollection;
    private Config                 $config;

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->config                = $this->_objectManager->get(Config::class);
        $this->resultRedirectFactory = $context->getResultRedirectFactory();
        $this->orderCollection       = new MagentoOrderCollection(
            $this->_objectManager,
            $this->getRequest(),
            null
        );
    }

    /**
     * Dispatch request
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws LocalizedException
     */
    public function execute()
    {
        try {
            $this->massAction();
        } catch (ApiException|MissingFieldException $e) {
            $this->_objectManager->get('Psr\Log\LoggerInterface')->critical($e);
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath(self::PATH_URI_ORDER_INDEX);
    }

    /**
     * Get selected items and process them
     *
     * @throws LocalizedException
     * @throws ApiException
     * @throws MissingFieldException
     * @throws \Exception
     */
    private function massAction(): void
    {
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

        if (Config::EXPORT_MODE_PPS === $this->config->getExportMode()) {
            $this->orderCollection->setFulfilment();

            return;
        }

        $this->orderCollection->setOptionsFromParameters()
                              ->setNewMagentoShipment()
        ;

        $this->orderCollection->reload();

        if (!$this->orderCollection->hasShipment()) {
            $this->messageManager->addErrorMessage(__(MagentoCollection::ERROR_ORDER_HAS_NO_SHIPMENT));

            return;
        }

        $this->orderCollection->syncMagentoToMyparcel()
                              ->setMagentoTrack()
                              ->setNewMyParcelTracks()
                              ->createMyParcelConcepts()
                              ->updateMagentoTrack()
        ;

        if (TrackAndTrace::VALUE_CONCEPT === $this->orderCollection->getOption('request_type')
            || $this->orderCollection->myParcelCollection->isEmpty()
        ) {
            return;
        }

        $this->orderCollection->addReturnShipments()
                              ->setPdfOfLabels()
                              ->updateMagentoTrack()
                              ->sendTrackEmails()
                              ->downloadPdfOfLabels()
        ;
    }

    /**
     * @param $orderIds int[]
     */
    private function addOrdersToCollection($orderIds)
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION);
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
        $order = $this->_objectManager->get(Order::class);
        // Go through the selected orders and check if the address details are correct
        foreach ($orderIds as $orderId) {
            $order->load($orderId);

            $fullStreet         = implode(" ", $order->getShippingAddress()->getStreet() ?? []);
            $postcode           = preg_replace('/\s+/', '', $order->getShippingAddress()->getPostcode());
            $destinationCountry = $order->getShippingAddress()->getCountryId();
            $keyOrderId         = array_search($orderId, $orderIds, true);

            // Validate the street and house number. If the address is wrong then get the orderId from the array and delete it.
            if (!ValidateStreet::validate($fullStreet, AbstractConsignment::CC_NL, $destinationCountry)) {
                $errorHuman = 'An error has occurred while validating the order number ' . $order->getIncrementId() . '. Check street.';
                $this->messageManager->addErrorMessage($errorHuman);

                unset($orderIds[$keyOrderId]);
            }
            // Validate the postcode. If the postcode is wrong then get the orderId from the array and delete it.
            if (!ValidatePostalCode::validate($postcode, $destinationCountry)) {
                $errorHuman = 'An error has occurred while validating the order number ' . $order->getIncrementId() . '. Check postcode.';
                $this->messageManager->addErrorMessage($errorHuman);

                unset($orderIds[$keyOrderId]);
            }
        }

        return $orderIds;
    }
}
