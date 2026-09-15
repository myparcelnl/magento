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
use MyParcelNL\Magento\Cron\UpdateStatus;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Model\Shipment\ShipmentBuilder;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\OrderGridColumns;

/**
 * Exports a shipment to MyParcel as Magento saves it.
 *
 * Fires on every sales_order_shipment_save_before in the shop, so it guards out the shipments that
 * are none of its business first.
 */
class NewShipment implements ObserverInterface
{
    const DEFAULT_LABEL_AMOUNT = 1;

    private ManagerInterface        $messageManager;
    private ObjectManager           $objectManager;
    private RedirectFactory         $redirectFactory;
    private RequestInterface        $request;
    private ?MagentoOrderCollection $orderCollection;
    private Config                  $config;
    private OrderGridColumns        $gridColumns;

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
        $this->orderCollection = $orderCollection;
        $this->config          = $this->objectManager->get(Config::class);
        $this->gridColumns     = $this->objectManager->get(OrderGridColumns::class);
    }

    /**
     * Built on first use, not in the constructor.
     *
     * This observer runs on every sales_order_shipment_save_before in the shop, and all but the
     * MyParcel ones return at the guard in execute(). MagentoOrderCollection resolves eleven
     * services of its own, so constructing it up front billed every other shipment save for a graph
     * it never touched.
     */
    private function orderCollection(): MagentoOrderCollection
    {
        if (null === $this->orderCollection) {
            $this->orderCollection = new MagentoOrderCollection($this->objectManager, $this->request);
        }

        return $this->orderCollection;
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
        $options = $this->orderCollection()->setOptionsFromParameters()
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

            // One track for the whole multicollo, because parseCreateResponse() drops the response's
            // secondary_shipments and colli 2..N have no id to store here. They are not lost: the
            // query response does carry them, so updateMagentoTrack() adds their tracks.
            if (1 === $collo) {
                $multiCollo = $this->orderCollection()->asMultiCollo($builtShipments[0], $amount);

                if (null !== $multiCollo) {
                    $builtShipments = [$multiCollo];
                    break;
                }
            }
        }

        if (Config::EXPORT_MODE_PPS === $this->config->getExportMode()) {
            $this->exportEntireOrder($shipment);
            $this->updateTrackGrid($shipment, true);

            return;
        }

        $report = $this->orderCollection()->getExportService()->createConcepts($builtShipments);

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
     * Export the order instance the request already holds, never a fresh load of it.
     *
     * Magento saves $shipment->getOrder() once more after this observer, in
     * Shipment\Save::_saveShipment(). A second instance still carries myparcel_uuid => null, and that
     * save writes the null back over the uuid setFulfilment() just stored.
     *
     * @throws \Exception
     */
    private function exportEntireOrder(Shipment $shipment): void
    {
        $this->orderCollection()->setOrderCollection([$shipment->getOrder()]);
        $this->orderCollection()->setFulfilment();
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
        $columns = $this->gridColumns->htmlForTracks($shipment->getTracksCollection());

        if ($entireOrder) {
            $columns['track_status'] = UpdateStatus::ORDER_STATUS_EXPORTED;
        }

        $order = $shipment->getOrder();

        // Set as well as written: Magento saves this order again after the observer, and without
        // these two the save would put the pre-observer values back over the column write.
        $order->setData('track_status', $columns['track_status'])
              ->setData('track_number', $columns['track_number']);

        $this->gridColumns->writeColumns((int) $order->getEntityId(), $columns);
    }
}
