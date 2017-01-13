<?php

namespace MyParcel\Magento\Controller\Adminhtml\Order;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Shipping\Model\Order\Track;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Api\OrderManagementInterface;
use MyParcelNL\Sdk\src\Helper\MyParcelAPI;
use MyParcel\Magento\Helper\Data;
use MyParcel\Magento\Model\Sales\MyParcelTrackTrace;

/**
 * Class MassDelete
 */
class MassTrackTraceLabel extends \Magento\Framework\App\Action\Action
{
    /**
     * @var string
     */
    protected $redirectUrl = 'sales/order/index';
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var MyParcelAPI
     */
    protected $api;

    /**
     * @var Track[]
     */
    protected $tracksToPrint;

    /**
     * @var Order
     */
    protected $modelOrder;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Grid\Collection
     */
    protected $modelOrderGrid;

    /**
     * @var Order\Shipment\Track
     */
    protected $modelTrack;

    /**
     * @var Order[]
     */
    protected $orders;

    /**
     * MassTrackTraceLabel constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
//        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        parent::__construct($context);
        $this->api = new MyParcelAPI();
        $this->helper = $this->_objectManager->create('MyParcel\Magento\Helper\Data');
        $this->resultRedirectFactory = $context->getResultRedirectFactory();

        $this->modelOrder = $this->_objectManager->create('Magento\Sales\Model\Order');
        $this->modelOrderGrid = $this->_objectManager->create('\Magento\Sales\Model\ResourceModel\Order\Grid\Collection');
        $this->modelTrack = $this->_objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
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
        return $this->resultRedirectFactory->create()->setPath($this->redirectUrl);
    }

    /**
     * Get selected items and process them
     */
    protected function massAction()
    {
        $orderIds = explode(',', $this->getRequest()->getParam('selected_ids'));
        $downloadLabel = $this->getRequest()->getParam('mypa_request_type', 'download') == 'download';
        $packageType = (int)$this->getRequest()->getParam('mypa_package_type', 1);

        if ($this->getRequest()->getParam('paper_size', null) == 'A4') {
            $positions = $this->getRequest()->getParam('mypa_positions', null);
        } else {
            $positions = null;
        }

        $this->setMagentoAndMyParcelTrack($orderIds, $downloadLabel, $packageType);

        if ($downloadLabel) {
            $this->api->setPdfOfLabels($positions);
            $this->updateMagentoTrack();
            $this->api->downloadPdfOfLabels();
        } else {
            $this->api->createConcepts();
            $this->updateMagentoTrack();
        }
    }

    /**
     * Set existing or create new Magento track and set API consignment to collection     *
     *
     * @param $orderIds    int[]
     * @param $downloadLabel bool
     * @param $packageType int
     *
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException on
     */
    private function setMagentoAndMyParcelTrack($orderIds, $downloadLabel, $packageType)
    {
        /** @var $magentoTrack Order\Shipment\Track */
        foreach ($orderIds as $orderId) {
            if (!$orderId) {
                continue;
            }
            $postNLTrack = null;

            $this->orders[$orderId] = $magentoOrder = $this->modelOrder->load($orderId);

            if ($downloadLabel && count($magentoOrder->getTracksCollection()) > 0) {

                // Use existing track
                foreach ($magentoOrder->getTracksCollection() as $magentoTrack) {
                    if (
                        $magentoTrack->getCarrierCode() == MyParcelTrackTrace::POSTNL_CARRIER_CODE &&
                        $magentoTrack->getData('api_id')
                    ) {
                        $postNLTrack = new MyParcelTrackTrace($this->_objectManager, $this->helper);

                        $postNLTrack
                            ->setApiKey($this->helper->getGeneralConfig('api/key'))
                            ->setApiId($magentoTrack->getData('api_id'))
                            ->setReferenceId($magentoTrack->getEntityId())
                            ->setPackageType($packageType);
                        $this->api->addConsignment($postNLTrack);
                    }
                }
            }
            if ($postNLTrack == null) {
                // Create new API consignment
                $postNLTrack = new MyParcelTrackTrace($this->_objectManager, $this->helper);
                $postNLTrack->createTrackTraceFromOrder($magentoOrder);
                $postNLTrack->convertDataFromMagentoToApi();
                $postNLTrack->setPackageType($packageType);
                $this->api->addConsignment($postNLTrack);
            }
        }
    }

    /**
     * Update all the tracks that made created via the API
     */
    private function updateMagentoTrack()
    {
        /** @var $magentoTrack Order\Shipment\Track */
        foreach ($this->api->getConsignments() as $postNLTrack) {
            $magentoTrack = $this->modelTrack->load($postNLTrack->getReferenceId());

            $magentoTrack->setData('api_id', $postNLTrack->getApiId());
            $magentoTrack->setData('api_status', $postNLTrack->getStatus());
            if ($postNLTrack->getBarcode()) {
                $magentoTrack->setTrackNumber($postNLTrack->getBarcode());
            }
            $magentoTrack->save();
        }

        $this->updateOrderGrid();
    }

    /**
     * Update column track_status in sales_order_grid
     */
    private function updateOrderGrid()
    {

        foreach ($this->orders as $orderId => &$order) {
            $aHtml = $this->getHtmlForGridColumns($order);
            if ($aHtml['track_status'])
                $order->setData('track_status', $aHtml['track_status']);
            if ($aHtml['track_number'])
                $order->setData('track_number', $aHtml['track_number']);
            $order->save();
        }
    }

    /**
     * @param $order Order
     *
     * @return array
     */
    private function getHtmlForGridColumns($order)
    {
        $data = ['track_status' => [], 'track_number' =>[]];
        $columnHtml = ['track_status' => '', 'track_number' => ''];

        /** @var $shipment Order\Shipment */
        foreach ($order->getShipmentsCollection() as $shipment) {

            // Set all track data in array
            foreach ($shipment->getTracks() as $track) {
                if($track->getData('api_status'))
                    $data['track_status'][] = __('status_' . $track->getData('api_status'));
                if($track->getData('track_number'))
                    $data['track_number'][] = $track->getData('track_number');
            }
        }

        // Create html
        if($data['track_status'])
            $columnHtml['track_status'] = implode(PHP_EOL, $data['track_status']);
        if($data['track_number'])
            $columnHtml['track_number'] = implode(PHP_EOL, $data['track_number']);

        return $columnHtml;
    }

}