<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;

/**
 * Builds the JSON answer an export gives the order and shipment grids: messages and the label request.
 *
 * The export never redirects, so Magento does not render its messages. They travel in this JSON
 * and the page shows them.
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
     * Get the label request for the PDF, or null when this export made no labels.
     *
     * It names Magento *shipment* ids: order ids also fetch labels of earlier exports. The ids go in
     * `params` to POST, not in the URL: a large selection exceeds nginx's 8 KB header limit.
     *
     * @param int[] $shipmentIds Magento shipment entity ids that carry a MyParcel shipment id
     * @param mixed $positions   the export option as it stands, not converted
     * @param bool  $notify      have the label request send the track & trace emails: the barcode
     *                           exists from that moment
     *
     * @return array{url: string, params: array<string,string|int>, failureLabel: string}|null
     */
    public function labels(
        array  $shipmentIds,
        string $requestType,
               $positions,
        bool   $notify = false
    ): ?array
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

        // The chosen A4 positions travel as one comma string and the controller splits them back
        // into an array. Left off entirely when there are none: see LabelPositions.
        $encoded = $this->labelPositions->encode($positions);

        if (null !== $encoded) {
            $params['positions'] = $encoded;
        }

        return [
            'url'    => $this->url->getUrl('myparcel/order/PrintMyParcelLabels'),
            'params' => $params,
            // Translated here rather than in JS, as the other admin components in this module do.
            'failureLabel' => (string) __('The MyParcel labels could not be downloaded.'),
        ];
    }

    /**
     * Take and clear the queued messages, or they show on the next page the admin opens.
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
