<?php
/**
 * Set MyParcel options to new track
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Adem Demir <adem@myparcel.nl>
 * @copyright   2010-2020 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Observer;

use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;

class CreateConceptAfterInvoice implements ObserverInterface
{
    private ObjectManager $objectManager;
    private MagentoOrderCollection    $orderCollection;
    private Config                    $config;

    protected ManagerInterface        $messageManager;

    /**
     * NewShipment constructor.
     *
     * @param MagentoOrderCollection|null $orderCollection
     */
    public function __construct(MagentoOrderCollection $orderCollection = null)
    {
        $this->objectManager   = $objectManager = ObjectManager::getInstance();
        $request               = $objectManager->get(RequestInterface::class);
        $this->config          = $objectManager->get(Config::class);
        $this->orderCollection = $orderCollection ?? new MagentoOrderCollection($objectManager, $request);
    }

    /**
     * Create MyParcel concept
     *
     * @param Observer $observer
     *
     * @return CreateConceptAfterInvoice
     * @throws Exception
     */
    public function execute(Observer $observer): self
    {
        if ($this->config->getGeneralConfig('print/create_concept_after_invoice')) {
            $order = $observer
                ->getEvent()
                ->getInvoice()
                ->getOrder()
            ;

            if (($order instanceof AbstractModel)
                && in_array(
                    $order->getState(),
                    ['pending', 'processing', 'new']
                )) {
                try {
                    $orderV1 = $this->objectManager->get(StoredAccount::class)->hasOrderV1ForExport((int) $order->getStoreId());
                } catch (LocalizedException $e) {
                    // Logged, not thrown: an exception here would fail the invoice.
                    Logger::warning(sprintf('Order %s was not exported after its invoice: %s', $order->getId(), $e->getMessage()));

                    return $this;
                }

                $this->exportAccordingToMode($order->getId(), $orderV1);
            }
        }

        return $this;
    }

    /**
     * Set MyParcel Tracks and update order grid
     *
     * @param $orderIds
     * @param bool $orderV1 whether the order's account exports entire orders
     *
     * @return CreateConceptAfterInvoice
     * @throws LocalizedException
     * @throws ApiException
     * @throws MissingFieldException
     * @throws Exception
     */
    private function exportAccordingToMode($orderIds, bool $orderV1)
    {
        $this->addOrdersToCollection($orderIds);

        $this->orderCollection
            ->setOptionsFromParameters()
            ->setNewMagentoShipment()
        ;

        if ($orderV1) {
            $this->orderCollection->exportAsOrderV1();

            return $this;
        }

        $this->orderCollection
            ->setMagentoTrack()
            ->setNewMyParcelTracks()
            ->createMyParcelConcepts()
            ->updateMagentoTrack()
        ;

        return $this;
    }

    /**
     * @param $orderIds int[]
     */
    private function addOrdersToCollection($orderIds): void
    {
        /**
         * @var Collection $collection
         */
        $collection = $this->objectManager->get(MagentoCollection::PATH_MODEL_ORDER_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
