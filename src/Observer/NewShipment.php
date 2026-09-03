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
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Model\Shipment\ShipmentBuilder;
use MyParcelNL\Sdk\Services\MultiCollo\MultiColloShipmentService;
use MyParcelNL\Magento\Service\Config;

class NewShipment implements ObserverInterface
{
    const DEFAULT_LABEL_AMOUNT = 1;

    private ManagerInterface       $messageManager;
    private ObjectManager          $objectManager;
    private RedirectFactory        $redirectFactory;
    private RequestInterface       $request;
    private MagentoOrderCollection $orderCollection;
    private Config                 $config;

    /**
     * NewShipment constructor.
     *
     * @param MagentoOrderCollection|null $orderCollection
     */
    public function __construct(MagentoOrderCollection $orderCollection = null)
    {
        $this->objectManager   = ObjectManager::getInstance();
        $this->request         = $this->objectManager->get(RequestInterface::class);
        $this->redirectFactory = $this->objectManager->get(RedirectFactory::class);
        $this->messageManager  = $this->objectManager->get(ManagerInterface::class);
        $this->orderCollection = $orderCollection ?? new MagentoOrderCollection($this->objectManager, $this->request);
        $this->config          = $this->objectManager->get(Config::class);
    }

    /**
     * Create MyParcel concept
     *
     * @param Observer $observer
     *
     * @return void
     * @throws \Exception
     */
    public function execute(Observer $observer): void
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
     * @param Shipment $shipment
     *
     * @throws \Exception
     */
    private function setMagentoAndMyParcelTrack(Shipment $shipment): void
    {
        $options = $this->orderCollection->setOptionsFromParameters()
                                         ->getOptions()
        ;

        if (isset($options['carrier']) && false === $options['carrier']) {
            unset($options['carrier']);
        }

        $amount = (int) ($options['label_amount'] ?? self::DEFAULT_LABEL_AMOUNT);

        $builder = new ShipmentBuilder($this->objectManager, $shipment->getOrder());

        /** @var BuiltShipment[] $builtShipments */
        $builtShipments = [];

        for ($collo = 1; $collo <= $amount; $collo++) {
            $track = $builder->createTrackForShipment($shipment);

            try {
                $builtShipments[] = $builder->build($track, $options, $collo);
            } catch (\Throwable $e) {
                // The builder says what went wrong; naming the order is the reporting layer's job,
                // here and in MagentoCollection::setNewMyParcelTracks().
                $this->messageManager->addErrorMessage(
                    sprintf('%s: %s', $shipment->getOrder()->getIncrementId(), $e->getMessage())
                );

                return;
            }

            // splitShipment() throws on a quantity of one, so one collo is never a multicollo — the
            // guard MagentoCollection::addGroupedShipments() has and this loop used to lack.
            // One track for the whole multicollo: the SDK's parseCreateResponse() drops the response's
            // secondary_shipments, so colli 2..N have no id to store (SDK issue 5 in the design doc).
            if (1 < $amount
                && 1 === $collo
                && $this->orderCollection->canUseMultiCollo($builtShipments[0]->shipment(), $builtShipments[0]->apiKey())) {
                $builtShipments = [
                    $builtShipments[0]->withShipment(
                        (new MultiColloShipmentService())->splitShipment($builtShipments[0]->shipment(), $amount)
                    ),
                ];
                break;
            }
        }

        if (Config::EXPORT_MODE_PPS === $this->config->getExportMode()) {
            $this->exportEntireOrder($shipment);
            $this->updateTrackGrid($shipment, true);

            return;
        }

        $report = $this->orderCollection->getExportService()->createConcepts($builtShipments);

        foreach ($report->failureMessages() as $message) {
            $this->messageManager->addErrorMessage($message);
        }

        // Each built shipment carries its own track, so nothing is paired by position any more.
        foreach ($builtShipments as $built) {
            if (! $built->track()->getData('myparcel_consignment_id')) {
                continue;
            }

            $shipment->addTrack($built->track());
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
     * @param Shipment $shipment
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
                 ->save()
        ;
    }
}
