<?php
/**
 * Set MyParcel options to new track
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

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\order\shipment\Collection;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;

class OrderPay implements ObserverInterface
{
    const DEFAULT_LABEL_AMOUNT = 1;

    protected $orderFactory;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var Track
     */
    private $modelTrack;

    /**
     * @var MagentoOrderCollection
     */
    private $orderCollection;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var MagentoShipmentCollection
     */
    private $shipmentCollection;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * NewShipment constructor.
     *
     * @param \MyParcelNL\Magento\Model\Sales\MagentoOrderCollection|null $orderCollection
     */
    public function __construct(MagentoOrderCollection $orderCollection = null)
    {
        $this->objectManager   = ObjectManager::getInstance();
        $this->request         = $this->objectManager->get('Magento\Framework\App\RequestInterface');
        $this->orderCollection = $orderCollection ?? new MagentoOrderCollection($this->objectManager, $this->request);
        $this->helper          = $this->objectManager->get('MyParcelNL\Magento\Helper\Data');
        $this->modelTrack      = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $this->orderFactory    = $this->objectManager->get('\Magento\Sales\Model\Order');
    }

    /**
     * Create MyParcel concept
     *
     * @param Observer $observer
     *
     * @return OrderPay
     * @throws \Exception
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if ($this->helper->getGeneralConfig('basic_settings/auto_export')) {
            $order   = $observer->getEvent()->getOrder();
            $orderid = $order->getId();

            if ($order instanceof \Magento\Framework\Model\AbstractModel) {
                if ($order->getState() == 'pending' || $order->getState() == 'processing') {
                    $this->setMagentoAndMyParcelTrack($orderid);
                }
            }
        }

        return $this;
    }

    /**
     * Set MyParcel Tracks and update order grid
     *
     * @param $orderIds
     *
     * @return OrderPay
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    private function setMagentoAndMyParcelTrack($orderIds)
    {
        $this->addOrdersToCollection($orderIds);

        $this->orderCollection
            ->setOptionsFromParameters()
            ->setNewMagentoShipment();

        $this->orderCollection
            ->setMagentoTrack()
            ->setMyParcelTrack()
            ->createMyParcelConcepts()
            ->updateGridByOrder();

        if (
            $this->orderCollection->getOption('request_type') == 'concept' ||
            $this->orderCollection->myParcelCollection->isEmpty()
        ) {
            return $this;
        }

        $this->orderCollection
            ->updateMagentoTrack();

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
        $collection = $this->objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
