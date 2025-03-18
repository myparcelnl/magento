<?php

namespace MyParcelNL\Magento\Controller\Adminhtml\Shipment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;
use MyParcelNL\Magento\Service\Config;

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
    const PATH_URI_SHIPMENT_INDEX = 'sales/shipment/index';

    /**
     * @var MagentoShipmentCollection
     */
    private        $shipmentCollection;
    private Config $configService;

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->configService = $context->getObjectManager()->get(Config::class);
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
     * @return ResultInterface|ResponseInterface
     * @throws LocalizedException
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
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
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     * @throws \Exception
     */
    private function massAction()
    {
        if (! $this->configService->apiKeyIsCorrect()) {
            $message = 'You have not entered the correct API key. Go to the general settings in the back office of MyParcel to generate the API Key.';
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

        $this->shipmentCollection
            ->setOptionsFromParameters();

        $this->shipmentCollection
            ->syncMagentoToMyparcel()
            ->setMagentoTrack()
            ->setNewMyParcelTracks()
            ->createMyParcelConcepts()
            ->updateMagentoTrack();

        if ('concept' === $this->shipmentCollection->getOption('request_type')) {
            return $this;
        }

        $this->shipmentCollection
            ->addReturnShipments()
            ->setPdfOfLabels()
            ->updateMagentoTrack()
            ->downloadPdfOfLabels();

        return $this;
    }

    /**
     * @param int[] $shipmentIds
     */
    private function addShipmentsToCollection($shipmentIds)
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection $collection
         */
        $collection = $this->_objectManager->get(MagentoCollection::PATH_MODEL_SHIPMENT_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $shipmentIds]);
        $this->shipmentCollection->setShipmentCollection($collection);
    }
}
