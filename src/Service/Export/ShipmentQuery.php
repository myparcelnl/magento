<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsShipment;
use MyParcelNL\Sdk\Concerns\HasUserAgent;

/**
 * Reads shipments by id, asking for the consumer portal link along with them.
 *
 * The SDK's ShipmentQueryService does the same fetch but hard-codes the link_consumer_portal flag to
 * null, and it is final, so this asks for the flag itself. The trait keeps the User-Agent header
 * identical to every other call the module makes, and ShipmentExportService::tagged() fills it.
 */
class ShipmentQuery
{
    use HasUserAgent;

    /**
     * Ids per request. They all go into one path segment, so an unbounded list becomes a URL the
     * web server rejects before the API ever sees it — nginx stops at 8 KB by default, which a
     * four-figure selection passes. A multiple of four, so a caller pairing this with A4 label
     * positions keeps its sheets aligned.
     */
    private const CHUNK_SIZE = 100;

    private ShipmentApi $api;

    public function __construct(ShipmentApi $api)
    {
        $this->api = $api;
    }

    /**
     * @param int[] $shipmentIds
     *
     * @return array<int, ShipmentDefsShipment> keyed by shipment id
     */
    public function findMany(array $shipmentIds): array
    {
        $shipments = [];

        foreach (array_chunk(array_map('intval', $shipmentIds), self::CHUNK_SIZE) as $chunk) {
            $shipments += $this->fetchChunk($chunk);
        }

        return $shipments;
    }

    /**
     * @param int[] $shipmentIds
     *
     * @return array<int, ShipmentDefsShipment> keyed by shipment id
     */
    private function fetchChunk(array $shipmentIds): array
    {
        $response = $this->api->getShipmentsById(
            implode(';', $shipmentIds),
            true,
            $this->getUserAgentHeader()
        );

        $data = $response->getData();

        if (null === $data) {
            return [];
        }

        $shipments = [];

        foreach ($data->getShipments() ?? [] as $shipment) {
            $shipments[(int) $shipment->getId()] = $shipment;
        }

        return $shipments;
    }
}
