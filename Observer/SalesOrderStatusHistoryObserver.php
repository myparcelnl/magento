<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History;
use MyParcelNL\Sdk\src\Collection\Fulfilment\OrderNotesCollection;
use MyParcelNL\Sdk\src\Model\Fulfilment\OrderNote;
use MyParcelNL\Magento\Helper\Data;

class SalesOrderStatusHistoryObserver implements ObserverInterface
{
    private Data $helper;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private ObjectManager $objectManager;

    public function __construct() {
        $this->objectManager   = ObjectManager::getInstance();
        $this->helper          = $this->objectManager->get(Data::class);
    }

    /**
     * @param  \Magento\Framework\Event\Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer): self
    {
        /** @var \Magento\Sales\Model\Order\Status\History $history */
        $history = $observer->getData()['status_history'];

        if (! is_a($history, History::class)
            || ! $history->getComment()) {
            return $this;
        }

        /** @var Order $magentoOrder */
        $magentoOrder = $this->objectManager->create(Order::class)
            ->loadByIncrementId($history->getOrder()->getIncrementId());

        $uuid = $magentoOrder->getData('myparcel_uuid');

        if (! $uuid) {
            return $this;
        }

        (new OrderNotesCollection())->setApiKey($this->helper->getApiKey())
            ->push(
                new OrderNote([
                        'orderUuid' => $uuid,
                        'note'      => $history->getComment(),
                        'author'    => 'webshop',
                    ]
                )
            )
            ->save();

        return $this;
    }
}
