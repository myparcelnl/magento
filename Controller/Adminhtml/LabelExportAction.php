<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Service\Export\ExportResponse;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;

/**
 * Shared shell of the two grid export controllers (orders and shipments).
 *
 * Both answer the JSON that ExportResponse builds and neither redirects: the grid calls them over
 * AJAX so the page stays put and the labels are fetched by a second request — a response can be a
 * PDF or JSON, not both.
 */
abstract class LabelExportAction extends Action
{
    protected ExportResponse $response;

    public function __construct(Context $context)
    {
        parent::__construct($context);

        $this->response = $this->_objectManager->get(ExportResponse::class);
    }

    public function execute(): ResultInterface
    {
        $labels = null;

        try {
            $labels = $this->massAction();
        } catch (ApiException|MissingFieldException $e) {
            Logger::critical('MyParcel export failed', LogContext::of($e));
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->response->json($labels);
    }

    /**
     * Runs the export for the grid's selection.
     *
     * @return array{url: string, failureLabel: string}|null the labels the page
     *         should fetch next, or null when this export produced none
     */
    abstract protected function massAction(): ?array;

    /**
     * @return string[] the grid selection, from either parameter shape the grid sends
     * @throws LocalizedException when nothing was selected
     */
    protected function selectedIds(): array
    {
        if ($this->getRequest()->getParam('selected_ids')) {
            $ids = explode(',', $this->getRequest()->getParam('selected_ids'));
        } else {
            $ids = $this->getRequest()->getParam('selected');
        }

        if (empty($ids)) {
            throw new LocalizedException(__('No items selected'));
        }

        return (array) $ids;
    }

    /**
     * The labels this run earned, or null when nothing carries a shipment id — so a failed export
     * never sends the page chasing a PDF that does not exist.
     */
    protected function labelsFor(MagentoCollection $collection, bool $notify): ?array
    {
        return $this->response->labels(
            $collection->getExportedShipmentIds(),
            (string) $collection->getOption('request_type'),
            $collection->getOption('positions'),
            $notify
        );
    }
}
