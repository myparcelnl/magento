<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Controller\Adminhtml\LabelExportAction;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;

/**
 * Creates a return label for each selected order and mails it to the consumer.
 *
 * POST only, like the exports it sits beside: it makes billable shipments and mails the consumer,
 * and an admin POST is form-key checked where a GET is not. It answers the grid's JSON instead of
 * redirecting, so its messages travel with the answer.
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
class SendMyParcelReturnMail extends LabelExportAction
{
    private MagentoOrderCollection $orderCollection;

    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->orderCollection = new MagentoOrderCollection(
            $this->_objectManager,
            $this->getRequest()
        );
    }

    /**
     * Always null: the return label is mailed, never downloaded, so there is no PDF to fetch after.
     *
     * @throws LocalizedException
     */
    protected function massAction(): ?array
    {
        $this->addOrdersToCollection($this->selectedIds());

        if (! $this->orderCollection->hasShipment()) {
            $this->messageManager->addErrorMessage(__(MagentoCollection::ERROR_ORDER_HAS_NO_SHIPMENT));

            return null;
        }

        $this->orderCollection->setNewMyParcelTracks();

        // Only on a run that reached an account. sendReturnLabelMails() has already said what went
        // wrong, and a success beside that error is the report the reviewer caught.
        if ($this->orderCollection->sendReturnLabelMails()) {
            $this->messageManager->addSuccessMessage(__('Return label mail is send to customer.'));
        }

        return null;
    }

    /**
     * @param string[] $orderIds
     */
    private function addOrdersToCollection(array $orderIds): void
    {
        /** @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection */
        $collection = $this->_objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
