<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Sdk\Exception\ValidationException;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\AccountNotActiveException;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;
use MyParcelNL\Magento\Service\Export\LabelPositions;
use MyParcelNL\Magento\Service\Export\ShipmentExportService;
use Throwable;
use MyParcelNL\Magento\Service\IdList;

/**
 * Streams the label PDF for shipments that have already been exported, then writes back the
 * barcodes that request minted and, when asked (notify), sends the track & trace emails — this is
 * the first moment a barcode exists to put in them.
 *
 * It creates nothing: shipments without a MyParcel shipment id are skipped, never exported.
 *
 * Both request methods, and both are used: the grid row's "Download label" is a GET, while an
 * export POSTs its shipment id list, which is too long for a URL. Drop either interface and
 * HttpMethodValidator answers that half with a 404 it logs at debug level only.
 */
class PrintMyParcelLabels extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::shipment';

    /** Read by label-download.js; keep the two spellings in step. */
    public const HEADER_INCOMPLETE = 'X-MyParcel-Label-Warnings';

    private ShipmentExportService     $exportService;
    private ShipmentCollectionFactory $shipmentCollectionFactory;
    private LabelPositions            $labelPositions;

    public function __construct(
        Context                   $context,
        ShipmentExportService     $exportService,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        LabelPositions            $labelPositions
    )
    {
        parent::__construct($context);

        $this->exportService             = $exportService;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->labelPositions            = $labelPositions;
    }

    public function execute(): ResultInterface
    {
        $shipments = $this->requestedShipments();

        if (null === $shipments) {
            return $this->failure(__('No shipments were given to print labels for.'));
        }

        // Built here rather than in refreshTracks(), so the ids the labels are fetched by and the
        // ids written back afterwards come from one collection and one grouping.
        $collection = new MagentoShipmentCollection($this->_objectManager, $this->getRequest());
        $collection->setShipmentCollection($shipments);

        try {
            $result = $this->exportService->fetchLabelPdf(
                $collection->getMyparcelConsignmentIdsByApiKey(),
                $this->labelPositions->decode($this->getRequest()->getParam('positions'))
            );
        } catch (AccountNotActiveException|ApiException|MissingFieldException|ValidationException $e) {
            Logger::warning('MyParcel labels: could not be fetched', LogContext::of($e));

            return $this->failure(__('The MyParcel labels could not be fetched. %1', $e->getMessage()));
        } catch (Throwable $e) {
            // Anything else is a bug: its message would put internals in front of the admin.
            Logger::critical('MyParcel labels: unexpected error', LogContext::of($e));

            return $this->failure(__('The MyParcel labels could not be fetched. Please check the log.'));
        }

        if ('' === $result['pdf']) {
            // The API's own words when it refused: "no labels" would imply nothing was exported,
            // while the shipments exist and are billable.
            return $this->failure(
                $result['errors']
                    ? __('The MyParcel labels could not be fetched. %1', implode(' ', $result['errors']))
                    : __('No MyParcel labels to download.')
            );
        }

        $this->refreshTracks($collection);

        return $this->pdfResponse($result['pdf'], $result['errors']);
    }

    /**
     * The shipments to print, or null when the request names none.
     *
     * The export passes the exact shipment ids it produced; the grid's per-row "Download label"
     * knows only its order and means every label that order has.
     */
    private function requestedShipments(): ?ShipmentCollection
    {
        $shipmentIds = $this->idsFromParam('shipment_ids');
        $orderIds    = $this->idsFromParam('order_ids');

        if (! $shipmentIds && ! $orderIds) {
            return null;
        }

        $collection = $this->shipmentCollectionFactory->create();

        return $shipmentIds
            ? $collection->addFieldToFilter('entity_id', ['in' => $shipmentIds])
            : $collection->addFieldToFilter('order_id', ['in' => $orderIds]);
    }

    /**
     * Writes the barcodes the label request just brought into existence, and mails them when the
     * export asked for that (notify).
     *
     * A shipment is created as a concept and has no barcode; asking for its label is what moves it
     * on and assigns one — so this is the first moment a barcode can be read or mailed.
     *
     * The PDF is already in hand, so a failure here is logged rather than thrown: losing the
     * download over a refresh would trade a small problem for a larger one.
     */
    private function refreshTracks(MagentoShipmentCollection $collection): void
    {
        try {
            $collection->updateMagentoTrack();

            if ($this->getRequest()->getParam('notify')) {
                $collection->sendTrackEmailFromShipments();
            }
        } catch (Throwable $e) {
            Logger::warning('MyParcel labels: printed, but the barcodes could not be written back', LogContext::of($e));
        }
    }

    /** @return int[] */
    private function idsFromParam(string $param): array
    {
        $ids = $this->getRequest()->getParam($param);
        $ids = is_string($ids) ? explode(',', $ids) : (array) $ids;

        return IdList::ints($ids);
    }

    /**
     * Inline for the tab the admin asked to open, an attachment otherwise — the same choice
     * downloadPdfOfLabels() used to make from the same option.
     *
     * A batch spanning several accounts can lose one of them and still merge a PDF. The body is the
     * document, so the accounts that failed travel in a header the download reads out loud —
     * url-encoded, because API error text is not header-safe.
     *
     * @param string[] $errors one per account that returned no labels
     */
    private function pdfResponse(string $pdf, array $errors): ResultInterface
    {
        $disposition = 'open_new_tab' === $this->getRequest()->getParam('request_type') ? 'inline' : 'attachment';

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'application/pdf');
        $result->setHeader(
            'Content-Disposition',
            sprintf('%s; filename="myparcel-label-%s.pdf"', $disposition, date('Y-m-d-His'))
        );
        $result->setHeader('Content-Length', (string) strlen($pdf));

        if ($errors) {
            $result->setHeader(
                self::HEADER_INCOMPLETE,
                rawurlencode((string) __('Some labels are missing. %1', implode(' ', $errors)))
            );
        }

        $result->setContents($pdf);

        return $result;
    }

    /**
     * Never a redirect.
     *
     * The grid fetches this URL, so a redirect would navigate the page and throw away the export
     * messages it had just rendered — the admin saw them flash and vanish. JSON lets the grid report
     * the failure without reloading.
     */
    private function failure(Phrase $message): ResultInterface
    {
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode(422);

        return $result->setData(['success' => false, 'message' => (string) $message]);
    }
}
