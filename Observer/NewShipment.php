<?php
/**
 * Set MyParcel options to new track
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

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\Manager;
use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Magento\Service\Config\ConfigService;

class NewShipment implements ObserverInterface
{
    const DEFAULT_LABEL_AMOUNT = 1;

    /**
     * @var Magento\Framework\Message\Manager
     */
    private $messageManager;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Backend\Model\View\Result\RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var MagentoOrderCollection
     */
    private $orderCollection;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    private $configService;

    /**
     * NewShipment constructor.
     *
     * @param \MyParcelNL\Magento\Model\Sales\MagentoOrderCollection|null $orderCollection
     */
    public function __construct(MagentoOrderCollection $orderCollection = null)
    {
        $this->objectManager   = ObjectManager::getInstance();
        $this->request         = $this->objectManager->get(RequestInterface::class);
        $this->redirectFactory = $this->objectManager->get(RedirectFactory::class);
        $this->messageManager  = $this->objectManager->get(Manager::class);
        $this->orderCollection = $orderCollection ?? new MagentoOrderCollection($this->objectManager, $this->request);
        $this->configService   = $this->objectManager->get(ConfigService::class);
    }

    /**
     * Create MyParcel concept
     *
     * @param Observer $observer
     *
     * @return void
     * @throws \Exception
     */
    public function execute(Observer $observer)
    {
        if ($this->request->getParam('mypa_create_from_observer')) {
            $this->request->setParams(['myparcel_track_email' => true]);
            $shipment = $observer->getEvent()->getShipment();

            try {
                $this->setMagentoAndMyParcelTrack($shipment);
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }

            if ($this->messageManager->hasMessages()) {
                $this->redirectFactory->create()->setPath('*/*/');
            }
        }
    }

    /**
     * Set MyParcel Tracks and update order grid
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     *
     * @throws \Exception
     */
    private function setMagentoAndMyParcelTrack(Shipment $shipment): void
    {
        $options = $this->orderCollection->setOptionsFromParameters()
                                         ->getOptions();

        if (isset($options['carrier']) && false === $options['carrier']) {
            unset($options['carrier']);
        }

        $amount = $options['label_amount'] ?? self::DEFAULT_LABEL_AMOUNT;

        /** @var \MyParcelNL\Magento\Model\Sales\TrackTraceHolder[] $trackTraceHolders */
        $trackTraceHolders = [];
        $i                 = 1;
        $useMultiCollo     = false;

        while ($i <= $amount) {
            // Set MyParcel options
            $trackTraceHolder = (new TrackTraceHolder($this->objectManager, $shipment->getOrder()))
                ->createTrackTraceFromShipment($shipment);
            $trackTraceHolder->convertDataFromMagentoToApi($trackTraceHolder->mageTrack, $options);

            if (1 === $i && $this->orderCollection->canUseMultiCollo($trackTraceHolder->consignment)) {
                $useMultiCollo = true;
            }

            if (! $useMultiCollo) {
                $this->orderCollection->myParcelCollection->addConsignment($trackTraceHolder->consignment);
            }

            $trackTraceHolders[] = $trackTraceHolder;
            $i++;
        }

        if ($useMultiCollo) {
            $firstTrackTraceHolder = $trackTraceHolders[0];
            $this->orderCollection->myParcelCollection->addMultiCollo(
                $firstTrackTraceHolder->consignment,
                $amount
            );
        }

        if (ConfigService::EXPORT_MODE_PPS === $this->configService->getExportMode()) {
            $this->exportEntireOrder($shipment);
            $this->updateTrackGrid($shipment, true);

            return;
        }

        $this->orderCollection->myParcelCollection
            ->createConcepts()
            ->setLatestData();

        foreach ($this->orderCollection->myParcelCollection as $consignment) {
            $trackTraceHolder = array_pop($trackTraceHolders);
            $trackTraceHolder->mageTrack
                ->setData('myparcel_consignment_id', $consignment->getConsignmentId())
                ->setData('myparcel_status', 1);
            $shipment->addTrack($trackTraceHolder->mageTrack);
        }

        $this->updateTrackGrid($shipment, false);
    }

    /**
     * @param $shipment
     *
     * @return void
     * @throws \Exception
     */
    private function exportEntireOrder($shipment): void
    {
        $orderId = $shipment->getOrderId();

        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $collection = $this->objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderId]);
        $this->orderCollection->setOrderCollection($collection);
        $this->orderCollection->setFulfilment();
    }

    /**
     * Update sales_order
     *
     * Magento puts our two columns sales_order automatically to sales_order_grid
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     *
     * @throws \Exception
     */
    private function updateTrackGrid($shipment, $entireOrder): void
    {
        $aHtml = $this->orderCollection->getHtmlForGridColumnsByTracks($shipment->getTracksCollection());

        if ($entireOrder) {
            $aHtml['track_status'] = 'Exported';
        }

        $shipment->getOrder()
                 ->setData('track_status', $aHtml['track_status'])
                 ->setData('track_number', $aHtml['track_number'])
                 ->save();
    }
}
