<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;

/**
 * The answer an export gives the grid: what happened, and where to fetch the labels it earned.
 *
 * Shared by the order and shipment grids, which run the same export over different collections. The
 * export never redirects, so nothing renders its messages for it — they travel in this JSON and the
 * page puts them on screen itself.
 */
class ExportResponse
{
    private ManagerInterface $messageManager;
    private UrlInterface     $url;
    private ResultFactory    $resultFactory;
    private LabelPositions   $labelPositions;

    public function __construct(
        ManagerInterface $messageManager,
        UrlInterface     $url,
        ResultFactory    $resultFactory,
        LabelPositions   $labelPositions
    )
    {
        $this->messageManager = $messageManager;
        $this->url            = $url;
        $this->resultFactory  = $resultFactory;
        $this->labelPositions = $labelPositions;
    }

    /**
     * @param array{url: string, failureLabel: string}|null $labels
     */
    public function json(?array $labels): ResultInterface
    {
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        return $result->setData([
            'messages' => $this->messages(),
            'labels'   => $labels,
        ]);
    }

    /**
     * Where the page should go for the PDF, or null when this export earned none.
     *
     * The labels are a second request because a response can be a PDF or JSON, not both. It names
     * Magento *shipment* ids: order ids would also fetch the labels of a shipment exported in an
     * earlier run, which the admin did not ask to reprint.
     *
     * @param int[] $shipmentIds Magento shipment entity ids that carry a MyParcel shipment id
     * @param mixed $positions   the export option as it stands, not converted
     * @param bool  $notify      have the label request send the track & trace emails — it is the
     *                           first moment a barcode exists to put in them
     *
     * @return array{url: string, failureLabel: string}|null
     */
    public function labels(array $shipmentIds, string $requestType, $positions, bool $notify = false): ?array
    {
        if (! $shipmentIds) {
            return null;
        }

        $params = [
            'shipment_ids' => implode(',', $shipmentIds),
            'request_type' => $requestType,
        ];

        if ($notify) {
            $params['notify'] = 1;
        }

        // An admin URL carries path segments, so the chosen A4 positions travel as one comma string
        // and the controller splits them back into an array. Left off entirely when there are none:
        // see LabelPositions.
        $encoded = $this->labelPositions->encode($positions);

        if (null !== $encoded) {
            $params['positions'] = $encoded;
        }

        return [
            'url' => $this->url->getUrl('myparcel/order/PrintMyParcelLabels', $params),
            // Translated here rather than in JS, as the other admin components in this module do.
            'failureLabel' => (string) __('The MyParcel labels could not be downloaded.'),
        ];
    }

    /**
     * Taken and cleared. Messages left in the pool would surface on whatever page the admin opens
     * next, long after the export they describe.
     *
     * @return array<int, array{type: string, text: string}>
     */
    private function messages(): array
    {
        $messages = [];

        foreach ($this->messageManager->getMessages(true)->getItems() as $message) {
            $messages[] = [
                'type' => $message->getType(),
                'text' => $message->getText(),
            ];
        }

        return $messages;
    }
}
