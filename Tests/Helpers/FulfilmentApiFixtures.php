<?php

declare(strict_types=1);

use MyParcelNL\Magento\Api\ShipmentStatus;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;

/**
 * Fulfilment API responses, the direction FulfilmentOrderBuilderMocks does not cover: that one
 * builds what we send, these build what comes back.
 *
 * order_shipments is a raw array in the SDK, so these mirror its wire shape rather than a model.
 * A fulfilment order can hold several shipments — extra colli for the same goods — which is what
 * apiOrderWithShipments() exists for; the single-shipment helpers are wrappers over it.
 */

/**
 * @param array<int,array{status:int, barcode?:string, id?:int}> $shipments
 */
function apiOrderWithShipments(string $incrementId, array $shipments): FulfilmentOrder
{
    $orderShipments = [];

    foreach ($shipments as $shipment) {
        $wire = ['status' => $shipment['status']];

        if (array_key_exists('barcode', $shipment)) {
            $wire['external_identifier'] = $shipment['barcode'];
        }

        if (array_key_exists('id', $shipment)) {
            $wire['id'] = $shipment['id'];
        }

        $orderShipments[] = ['shipment' => $wire];
    }

    return new FulfilmentOrder([
        'external_identifier' => $incrementId,
        'order_shipments'     => $orderShipments,
    ]);
}

function apiOrder(string $incrementId, int $status, string $barcode, ?int $shipmentId): FulfilmentOrder
{
    $shipment = ['status' => $status, 'barcode' => $barcode];

    if (null !== $shipmentId) {
        $shipment['id'] = $shipmentId;
    }

    return apiOrderWithShipments($incrementId, [$shipment]);
}

function shippedApiOrder(string $incrementId, string $barcode = '3SABC', ?int $shipmentId = null): FulfilmentOrder
{
    return apiOrder($incrementId, ShipmentStatus::PRINTED_MINIMUM, $barcode, $shipmentId);
}

/** Status 1 is concept: created in the backoffice, not yet shipped. */
function conceptApiOrder(string $incrementId, string $barcode = '3SABC'): FulfilmentOrder
{
    return apiOrder($incrementId, ShipmentStatus::CONCEPT, $barcode, null);
}
