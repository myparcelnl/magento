<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\IdList;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Service\ShipmentOptions\OrderOptionsWriter;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsFormFactory;
use Throwable;

/**
 * Shared shell of the shipment options modal's two calls. Both take order_ids or shipment_ids,
 * work on the orders behind them, and answer JSON with either a result or an error.
 */
abstract class AbstractShipmentOptions extends Action implements HttpPostActionInterface
{
    /** The same resource as the label export, which this modal sits in front of. */
    public const ADMIN_RESOURCE = 'Magento_Sales::shipment';

    protected OrderOptionsWriter         $writer;
    protected ShipmentOptionsFormFactory $formFactory;
    private OrderCollectionFactory       $orderCollectionFactory;
    private ShipmentCollectionFactory    $shipmentCollectionFactory;

    public function __construct(
        Context                    $context,
        OrderOptionsWriter         $writer,
        ShipmentOptionsFormFactory $formFactory,
        OrderCollectionFactory     $orderCollectionFactory,
        ShipmentCollectionFactory  $shipmentCollectionFactory
    ) {
        parent::__construct($context);

        $this->writer                    = $writer;
        $this->formFactory               = $formFactory;
        $this->orderCollectionFactory    = $orderCollectionFactory;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
    }

    public function execute(): Json
    {
        /** @var Json $json */
        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            return $json->setData($this->answer($this->orders()));
        } catch (LocalizedException $e) {
            return $json->setData(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::critical('MyParcel shipment options: unexpected error', LogContext::of($e));

            return $json->setData(['error' => (string) __('The MyParcel options could not be processed. Please check the log.')]);
        }
    }

    /**
     * @param Order[] $orders
     *
     * @throws LocalizedException
     */
    abstract protected function answer(array $orders): array;

    /**
     * @return Order[]
     * @throws LocalizedException when nothing was selected
     */
    private function orders(): array
    {
        $orderIds    = IdList::fromParam($this->getRequest()->getParam('order_ids'));
        $shipmentIds = IdList::fromParam($this->getRequest()->getParam('shipment_ids'));

        if ($shipmentIds) {
            $orderIds = array_merge($orderIds, $this->shipmentCollectionFactory->create()
                ->addFieldToFilter('entity_id', ['in' => $shipmentIds])
                ->getColumnValues('order_id'));
        }

        $orders = $orderIds
            ? $this->orderCollectionFactory->create()
                ->addFieldToFilter('entity_id', ['in' => IdList::ints($orderIds)])
                ->getItems()
            : [];

        if (! $orders) {
            throw new LocalizedException(__('No items selected'));
        }

        return array_values($orders);
    }
}
