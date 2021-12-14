<?php
/**
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelNL\Magento\Model\Source\ReturnInTheBox;
use MyParcelNL\Magento\Observer\NewShipment;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\BaseConsignment;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelNL\Magento\Model\Sales
 */
class MagentoCollection implements MagentoCollectionInterface
{
    const PATH_HELPER_DATA            = 'MyParcelNL\Magento\Helper\Data';
    const PATH_MODEL_ORDER            = '\Magento\Sales\Model\ResourceModel\Order\Collection';
    const PATH_MODEL_SHIPMENT         = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Collection';
    const PATH_ORDER_GRID             = '\Magento\Sales\Model\ResourceModel\Order\Grid\Collection';
    const PATH_ORDER_TRACK            = 'Magento\Sales\Model\Order\Shipment\Track';
    const PATH_MANAGER_INTERFACE      = '\Magento\Framework\Message\ManagerInterface';
    const PATH_ORDER_TRACK_COLLECTION = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';
    const ERROR_ORDER_HAS_NO_SHIPMENT = 'No shipment can be made with this order. Shipments can not be created if the status is On Hold or if the product is digital.';

    /**
     * @var MyParcelCollection
     */
    public $myParcelCollection;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    public $request = null;

    /**
     * @var TrackSender
     */
    protected $trackSender;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var Order\Shipment\Track
     */
    protected $modelTrack;

    /**
     * @var \Magento\Framework\App\AreaList
     */
    protected $areaList;

    /**
     * @var \Magento\Framework\Message\ManagerInterface $messageManager
     */
    protected $messageManager;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    protected $helper;

    protected $options = [
        'create_track_if_one_already_exist' => true,
        'request_type'                      => 'download',
        'package_type'                      => 'default',
        'carrier'                           => 'postnl',
        'positions'                         => null,
        'signature'                         => null,
        'only_recipient'                    => null,
        'return'                            => null,
        'large_format'                      => null,
        'age_check'                         => null,
        'insurance'                         => null,
        'label_amount'                      => NewShipment::DEFAULT_LABEL_AMOUNT,
        'digital_stamp_weight'              => null,
        'return_in_the_box'                 => false,
    ];

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param ObjectManagerInterface                  $objectManager
     * @param \Magento\Framework\App\RequestInterface $request
     * @param null                                    $areaList
     */
    public function __construct(ObjectManagerInterface $objectManager, $request = null, $areaList = null)
    {
        // @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
        if ($areaList) {
            $this->areaList = $areaList;
        }

        $this->objectManager = $objectManager;
        $this->request       = $request;
        $this->trackSender   = $this->objectManager->get('MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender');

        $this->helper             = $objectManager->create(self::PATH_HELPER_DATA);
        $this->modelTrack         = $objectManager->create(self::PATH_ORDER_TRACK);
        $this->messageManager     = $objectManager->create(self::PATH_MANAGER_INTERFACE);
        $this->myParcelCollection = (new MyParcelCollection())->setUserAgents(
            ['Magento2' => $this->helper->getVersion()]
        );
    }

    /**
     * Set options from POST or GET variables
     *
     * @return $this
     */
    public function setOptionsFromParameters()
    {
        // If options isset
        foreach (array_keys($this->options) as $option) {
            if ($this->request->getParam('mypa_' . $option) === null) {
                if ($this->request->getParam('mypa_extra_options_checkboxes_in_form') === null) {
                    // Use default options
                    $this->options[$option] = null;
                } else {
                    // Checkbox isset but false
                    $this->options[$option] = false;
                }
            } else {
                $this->options[$option] = $this->request->getParam('mypa_' . $option);
            }
        }

        $label_amount = $this->request->getParam('mypa_label_amount') ?? NewShipment::DEFAULT_LABEL_AMOUNT;

        if ($label_amount) {
            $this->options['label_amount'] = $label_amount;
        }

        // Remove position if paper size == A6
        if ($this->request->getParam('mypa_paper_size', 'A6') != 'A4') {
            $this->options['positions'] = null;
        }

        if ($this->request->getParam('mypa_request_type') == null) {
            $this->options['request_type'] = 'download';
        }

        if ($this->request->getParam('mypa_request_type') != 'concept') {
            $this->options['create_track_if_one_already_exist'] = false;
        }

        $returnInTheBox = $this->helper->getGeneralConfig('print/return_in_the_box');
        if (ReturnInTheBox::NO_OPTIONS === $returnInTheBox || ReturnInTheBox::EQUAL_TO_SHIPMENT === $returnInTheBox) {
            $this->options['return_in_the_box'] = $returnInTheBox;
        }

        return $this;
    }

    /**
     * Get all options
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Get option by key
     *
     * @param $option
     *
     * @return mixed
     */
    public function getOption($option)
    {
        return $this->options[$option];
    }

    /**
     * Add MyParcel consignment to collection
     *
     * @param $consignment BaseConsignment
     *
     * @return $this
     * @throws \Exception
     */
    public function addConsignment(BaseConsignment $consignment)
    {
        $this->myParcelCollection->addConsignment($consignment);

        return $this;
    }

    /**
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->helper->getApiKey();
    }

    /**
     * @return string
     */
    public function getExportMode(): string
    {
        return $this->helper->getExportMode();
    }

    /**
     * @return bool
     */
    public function apiKeyIsCorrect(): bool
    {
        return $this->helper->apiKeyIsCorrect();
    }

    /**
     * Update sales_order table
     *
     * @param $orderId
     *
     * @return array
     */
    public function getHtmlForGridColumns($orderId)
    {
        /**
         * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
         */
        // Temporarily fix to translate in cronjob
        if (! empty($this->areaList)) {
            $areaObject = $this->areaList->getArea(\Magento\Framework\App\Area::AREA_ADMINHTML);
            $areaObject->load(\Magento\Framework\App\Area::PART_TRANSLATE);
        }
        $tracks = $this->getTracksCollectionByOrderId($orderId);

        $data       = ['track_status' => [], 'track_number' => []];
        $columnHtml = ['track_status' => '', 'track_number' => ''];

        /**
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $track
         */
        foreach ($tracks as $track) {
            // Set all Track data in array
            if ($track['myparcel_status'] !== null) {
                $data['track_status'][] = __('status_' . $track['myparcel_status']);
            }
            if ($track['track_number']) {
                $data['track_number'][] = $track['track_number'];
            }
        }

        // Create html
        if ($data['track_status']) {
            $columnHtml['track_status'] = implode('<br>', $data['track_status']);
        }
        if ($data['track_number']) {
            $columnHtml['track_number'] = json_encode($data['track_number']);
        }

        return $columnHtml;
    }

    /**
     * Check if track already exists
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment $shipment
     *
     * @return bool
     */
    protected function shipmentHasTrack($shipment)
    {
        return $this->getTrackByShipment($shipment)->count() == 0 ? false : true;
    }

    /**
     * Create new Magento Track
     *
     * @param Order\Shipment $shipment
     *
     * @return \Magento\Sales\Model\Order\Shipment\Track
     * @throws \Exception
     */
    protected function setNewMagentoTrack($shipment)
    {
        /** @var \Magento\Sales\Model\Order\Shipment\Track $track */
        $track = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $track
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(TrackTraceHolder::MYPARCEL_CARRIER_CODE)
            ->setTitle(TrackTraceHolder::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY)
            ->save();

        return $track;
    }

    /**
     * Get all tracks
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment $shipment
     *
     * @return \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection
     */
    protected function getTrackByShipment($shipment)
    {
        /* @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $collection */
        $collection = $this->objectManager->create(self::PATH_ORDER_TRACK_COLLECTION);
        $collection
            ->addAttributeToFilter('parent_id', $shipment->getId());

        return $collection;
    }

    /**
     * Get MyParcel Track from Magento Track
     *
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return TrackTraceHolder $myParcelTrack
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function createConsignmentAndGetTrackTraceHolder($magentoTrack): TrackTraceHolder
    {
        $trackTraceHolder = new TrackTraceHolder(
            $this->objectManager,
            $this->helper,
            $magentoTrack->getShipment()->getOrder()
        );
        $trackTraceHolder->convertDataFromMagentoToApi($magentoTrack, $this->options);

        return $trackTraceHolder;
    }

    /**
     * @param $shipments
     *
     * @return $this
     */
    protected function syncMagentoToMyParcelForShipments($shipments): self
    {
        $consignmentIds = [];

        foreach ($shipments as $shipment) {
            $trackCollection = $shipment->getAllTracks();
            foreach ($trackCollection as $magentoTrack) {
                $consignmentId = (int) $magentoTrack->getData('myparcel_consignment_id');
                if ($consignmentId) {
                    $consignmentIds[] = $consignmentId;
                }
            }
        }
        try {
            $this->myParcelCollection->addConsignmentByConsignmentIds(
                $consignmentIds,
                $this->getApiKey()
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this;
    }

    /**
     * Create MyParcel concepts
     *
     * @return $this
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
     */
    public function createMyParcelConcepts(): self
    {
        if (! count($this->myParcelCollection)) {
            $this->messageManager->addWarningMessage(__('myparcelnl_magento_error_no_shipments_to_process'));
            return $this;
        }

        try {
            $this->myParcelCollection->createConcepts();
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this;
        }

        $this->myParcelCollection->setLatestData();

        return $this;
    }

    /**
     * Add MyParcel Track from Magento Track
     *
     * @return $this
     * @throws \Exception
     */
    public function setNewMyParcelTracksByShipment($shipments): self
    {
        $parents = []; // TODO JOERI Ediefy the code of this method
        /**
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $magentoTrack
         */
        foreach ($shipments as $shipment) {
            foreach ($this->getTrackByShipment($shipment)->getItems() as $magentoTrack) {
            //foreach ($shipment->getAllTracks() as $magentoTrack) {
                if ($magentoTrack->getData('myparcel_consignment_id')) {
                    continue;
                }

                if ($magentoTrack->getCarrierCode() === TrackTraceHolder::MYPARCEL_CARRIER_CODE) {

                    $parentId = $magentoTrack->getData('parent_id');

                    if (isset($parents[$parentId])) {
                        $parents[$parentId]['colli']++;
                    } else {
                        $consignment = $this->createConsignmentAndGetTrackTraceHolder($magentoTrack)->consignment;

                        $parents[$parentId] = [
                            'consignment' => $consignment,
                            'colli'       => 1,
                        ];
                    }
                }
            }
        }

        foreach ($parents as $index => $arr) {
            $consignment = $arr['consignment'];
            if (1 === $arr['colli']) {
                $this->myParcelCollection->addConsignment($consignment);
            } else {
                $this->myParcelCollection->addMultiCollo($consignment, (int) $arr['colli']);
            }
        }

        return $this;
    }

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function addReturnInTheBox(string $returnOptions): void
    {
        $this->myParcelCollection
            ->generateReturnConsignments(
                false,
                function (
                    AbstractConsignment $returnConsignment,
                    AbstractConsignment $parent
                ) use ($returnOptions): AbstractConsignment {
                    $returnConsignment->setLabelDescription(
                        'Return: ' . $parent->getLabelDescription() .
                        ' This label is valid until: ' . date("d-m-Y", strtotime("+ 28 days"))
                    );
                    $returnConsignment->setReferenceId($parent->getReferenceId());

                    if (ReturnInTheBox::NO_OPTIONS === $returnOptions) {
                        $returnConsignment->setOnlyRecipient(false);
                        $returnConsignment->setSignature(false);
                        $returnConsignment->setAgeCheck(false);
                        $returnConsignment->setReturn(false);
                        $returnConsignment->setLargeFormat(false);
                        $returnConsignment->setInsurance(false);
                    }

                    return $returnConsignment;
                }
            );
    }

    /**
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection|array $shipments
     *
     * @return $this
     * @throws \Exception
     */
    protected function updateMagentoTrackByShipment($shipments): self
    {
        //echo ' <textarea cols="100" rows="60">'; // JOERI
        /**
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $magentoTrack
         */
        foreach ($shipments as $shipment) {
            //echo get_class($shipment) . ': ' . $shipment->getId() . " \n"; // JOERI
            $consignments    = $this->myParcelCollection->getConsignmentsByReferenceId($shipment->getEntityId());
            //echo 'entity_id: ' . $shipment->getEntityId() . '  ' . $this->myParcelCollection->count() . "\n"; // JOERI
//            foreach ($this->myParcelCollection as $joeri) {
//                echo get_class($joeri) . ': ' . $joeri->getConsignmentId() . ', barcode: ' . $joeri->getBarcode() . " \n";
//            }
            $trackCollection = $this->getTrackByShipment($shipment)->getItems(); // this gets the tracks
            //$trackCollection = $shipment->getAllTracks(); // this does not (??)
            foreach ($trackCollection as $magentoTrack) {
                //echo $magentoTrack->getData('myparcel_consignment_id') . " \n"; // JOERI
                $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId(
                    $magentoTrack->getData('myparcel_consignment_id')
                );

                if (! $myParcelTrack) {
                    if ($consignments->isEmpty()) {
                        //echo 'consignments R empty' . " \n"; // JOERI
                        continue;
                    }
                    $myParcelTrack = $consignments->pop();
                    $magentoTrack->setData('myparcel_consignment_id', $myParcelTrack->getConsignmentId());
                    //echo 'NIEUWE: ' . $myParcelTrack->getConsignmentId() . " \n"; // JOERI
                }
                //echo 'BESTAANDE: ' . $myParcelTrack->getConsignmentId() . " \n"; // JOERI

                if ($myParcelTrack->getStatus()) {
                    $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());
                }

                if ($myParcelTrack->getBarcode()) {
                    $magentoTrack->setTrackNumber($myParcelTrack->getBarcode());
                    //echo 'barcode: ' . $myParcelTrack->getBarcode() . " \n"; // JOERI
                }

                $magentoTrack->save();
            }
        }
        //die(' </textarea> WOEFDRAM OUWE'); // JOERI

        return $this->updateOrderGridByShipment($shipments);
    }

    public function addReturnShipments(): self
    { // can only be called once
        // check if there are shipments at all before return in the box
        $returnInTheBoxOptions = $this->options['return_in_the_box'] ?? null;
        if ($returnInTheBoxOptions) {
            $this->addReturnInTheBox($returnInTheBoxOptions);
        }

        return $this;
    }

    /**
     * @param $shipments
     *
     * @return $this
     * @throws \Exception
     */
    protected function updateOrderGridByShipment($shipments): self
    {
        if (! $shipments) {
            return $this;
        }

        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection $shipment
         * @var Order                                                        $order
         */
        foreach ($shipments as $shipment) {
            if (! $shipment) {
                continue;
            }
            if (! method_exists($shipment, 'getOrder')) {
                continue;
            }

            $order = $shipment->getOrder();
            $aHtml = $this->getHtmlForGridColumns($order->getId());

            if ($aHtml['track_status']) {
                $order->setData('track_status', $aHtml['track_status']);
            }
            if ($aHtml['track_number']) {
                $order->setData('track_number', $aHtml['track_number']);
            }
            $order->save();
        }

        return $this;
    }

    /**
     * @param $orderId
     *
     * @return array
     */
    private function getTracksCollectionByOrderId($orderId)
    {
        /** @var \Magento\Framework\App\ResourceConnection $connection */
        $connection = $this->objectManager->create('\Magento\Framework\App\ResourceConnection');
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_track')]
                           )
                           ->where('main_table.order_id=?', $orderId);
        $tracks     = $conn->fetchAll($select);

        return $tracks;
    }
}
