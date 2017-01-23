<?php

namespace MyParcelNL\Magento\Controller\Adminhtml\Order;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResponseInterface;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Model\Order;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\MyParcelTrackTrace;

/**
 * Short_description
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 * @package   MyParcelNL\Magento
 * @author    Reindert Vetter <reindert@myparcel.nl>
 * @copyright 2010-2016 MyParcel
 * @license   http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link      https://github.com/myparcelnl/magento
 * @since     File available since Release 0.1.0
 */
class MassTrackTraceLabel extends \Magento\Framework\App\Action\Action
{
    const PATH_HELPER_DATA = 'MyParcelNL\Magento\Helper\Data';
    const PATH_MODEL_ORDER = 'Magento\Sales\Model\Order';
    const PATH_ORDER_GRID = '\Magento\Sales\Model\ResourceModel\Order\Grid\Collection';
    const PATH_ORDER_TRACK = 'Magento\Sales\Model\Order\Shipment\Track';

    const REDIRECT_URL = 'sales/order/index';

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var MyParcelCollection
     */
    private $api;

    /**
     * @var Order
     */
    private $modelOrder;

    /**
     * @var Order\Shipment\Track
     */
    private $modelTrack;

    /**
     * @var Order[]
     */
    private $orders;

    /**
     * MassTrackTraceLabel constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->api = new MyParcelCollection();
        $this->helper = $context->getObjectManager()->create(self::PATH_HELPER_DATA);
        $this->resultRedirectFactory = $context->getResultRedirectFactory();

        $this->modelOrder = $context->getObjectManager()->create(self::PATH_MODEL_ORDER);
        $this->modelTrack = $context->getObjectManager()->create(self::PATH_ORDER_TRACK);
    }

    /**
     * Dispatch request
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function execute()
    {
        $this->massAction();

        return $this->resultRedirectFactory->create()->setPath(self::REDIRECT_URL);
    }

    /**
     * Get selected items and process them
     */
    private function massAction()
    {
        if ($this->getRequest()->getParam('selected_ids')) {
            $orderIds = explode(',', $this->getRequest()->getParam('selected_ids'));
        } else {
            $orderIds = null;
        }

        $downloadLabel = $this->getRequest()->getParam('mypa_request_type', 'download') == 'download';
        $packageType = (int)$this->getRequest()->getParam('mypa_package_type', 1);

        if ($this->getRequest()->getParam('paper_size', null) == 'A4') {
            $positions = $this->getRequest()->getParam('mypa_positions', null);
        }
        else {
            $positions = null;
        }

        if (empty($orderIds))
            throw new \Exception('No items selected');

        $this->setMagentoAndMyParcelTrack($orderIds, $downloadLabel, $packageType);

        if ($downloadLabel) {
            $this->api->setPdfOfLabels($positions);
            $this->updateMagentoTrack();
            $this->api->downloadPdfOfLabels();
        }
        else {
            $this->api->createConcepts();
            $this->updateMagentoTrack();
        }
    }

    /**
     * Set existing or create new Magento track and set API consignment to collection     *
     *
     * @param $orderIds      int[]
     * @param $downloadLabel bool
     * @param $packageType   int
     *
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException on
     *
     * @todo; move to model
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

            if ($downloadLabel && !empty($magentoOrder->getTracksCollection())) {
                // Use existing track
                foreach ($magentoOrder->getTracksCollection() as $magentoTrack) {
                    if ($magentoTrack->getCarrierCode() ==
                        MyParcelTrackTrace::POSTNL_CARRIER_CODE &&
                        $magentoTrack->getData('myparcel_consignment_id')
                    ) {
                        $postNLTrack = new MyParcelTrackTrace($this->_objectManager, $this->helper);

                        $postNLTrack
                            ->setApiKey($this->helper->getGeneralConfig('api/key'))
                            ->setMyParcelConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
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
     *
     * @todo; move to model
     */
    private function updateMagentoTrack()
    {
        /** @var $magentoTrack Order\Shipment\Track */
        foreach ($this->api->getConsignments() as $postNLTrack) {
            $magentoTrack = $this->modelTrack->load($postNLTrack->getReferenceId());

            $magentoTrack->setData('myparcel_consignment_id', $postNLTrack->getMyParcelConsignmentId());
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
     *
     * @todo; move to model
     */
    private function updateOrderGrid()
    {

        foreach ($this->orders as $orderId => &$order) {
            $aHtml = $this->getHtmlForGridColumns($order);
            if ($aHtml['track_status']) {
                $order->setData('track_status', $aHtml['track_status']);
            }
            if ($aHtml['track_number']) {
                $order->setData('track_number', $aHtml['track_number']);
            }
            $order->save();
        }
    }

    /**
     * @param $order Order
     *
     * @todo; move to model
     *
     * @return array
     */
    private function getHtmlForGridColumns($order)
    {
        $data = ['track_status' => [], 'track_number' => []];
        $columnHtml = ['track_status' => '', 'track_number' => ''];

        /** @var $shipment Order\Shipment */
        foreach ($order->getShipmentsCollection() as $shipment) {
            // Set all track data in array
            foreach ($shipment->getTracks() as $track) {
                if ($track->getData('api_status')) {
                    $data['track_status'][] = __('status_' . $track->getData('api_status'));
                }
                if ($track->getData('track_number')) {
                    $data['track_number'][] = $track->getData('track_number');
                }
            }
        }

        // Create html
        if ($data['track_status']) {
            $columnHtml['track_status'] = implode(PHP_EOL, $data['track_status']);
        }
        if ($data['track_number']) {
            $columnHtml['track_number'] = implode(PHP_EOL, $data['track_number']);
        }

        return $columnHtml;
    }
}
