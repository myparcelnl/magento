<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Service\Export\LabelPdfMerger;
use MyParcelNL\Magento\Service\Export\LabelPositions;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Export\ShipmentExportService;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ApiException;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsShipment;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesPostShipmentsV12;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesPostShipmentsV12Data;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentPostShipmentsRequestV11;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * Fixtures for TR-000006's export scenarios.
 *
 * The mocked ShipmentApi is the seam: ShipmentCreateService is final and builds its own request, so
 * the only place to observe what was sent per key is the api double it was handed.
 */

/**
 * A Track double that remembers what was written to it, so per-chunk persistence is observable.
 *
 * It carries an entity id by default, as a grid track loaded from the database does; pass
 * $trackEntityId null for the observer flow's unsaved track, which must not be save()d.
 */
function exportTrack(?int $existingShipmentId = null, ?int $trackEntityId = 1, ?int &$saves = null): Track
{
    $data  = [];
    $saves = 0;

    if (null !== $existingShipmentId) {
        $data['myparcel_consignment_id'] = $existingShipmentId;
    }

    $track = Mockery::mock(Track::class);
    $track->shouldReceive('getId')->andReturn($trackEntityId);
    $track->shouldReceive('getData')->andReturnUsing(function (?string $key = null) use (&$data) {
        return null === $key ? $data : ($data[$key] ?? null);
    });
    $track->shouldReceive('setData')->andReturnUsing(function (string $key, $value) use (&$data, $track) {
        $data[$key] = $value;

        return $track;
    });
    $track->shouldReceive('save')->andReturnUsing(function () use (&$saves, $track) {
        $saves++;

        return $track;
    });

    return $track;
}

function builtShipmentFor(string $apiKey, string $incrementId, ?int $existingShipmentId = null, ?Track $track = null): BuiltShipment
{
    $shipment = (new Shipment())
        ->setCarrier(1)
        ->setReferenceIdentifier($incrementId . '-1')
        ->setRecipient(['cc' => 'NL', 'postal_code' => '2132JE', 'city' => 'Hoofddorp', 'street' => 'Antareslaan', 'number' => '31', 'person' => 'Test'])
        ->setPhysicalProperties(['weight' => 1000])
        ->setOptions(['package_type' => 1]);

    return new BuiltShipment($shipment, $track ?? exportTrack($existingShipmentId), $apiKey, $incrementId);
}

/**
 * The request object, wherever it sits in the argument list.
 *
 * postShipments() takes $user_agent first at beta.15 and sixth at beta.31, so a fixture that binds
 * to either position silently stops observing anything when the pin moves — it did.
 *
 * @return \MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentPostShipmentsRequestV11
 */
function postShipmentsRequestIn(array $args)
{
    foreach ($args as $arg) {
        if ($arg instanceof ShipmentPostShipmentsRequestV11) {
            return $arg;
        }
    }

    throw new RuntimeException('postShipments() was called without a request object');
}

/** Answers every create by echoing each request's own references back, in reverse order. */
function makeShipmentApiSpy(array &$calls, bool $failing = false, bool $reverse = true): ShipmentApi
{
    $api = Mockery::mock(ShipmentApi::class);
    $api->shouldReceive('postShipments')->andReturnUsing(
        function (...$args) use (&$calls, $failing, $reverse) {
            $shipments = postShipmentsRequestIn($args)->getData()->getShipments();
            $calls[]   = array_map(static fn(Shipment $s): string => (string) $s->getReferenceIdentifier(), $shipments);

            if ($failing) {
                throw new RuntimeException('the API refused this chunk');
            }

            $created = [];
            $nextId  = 1000 + count($calls) * 100;
            $ordered = $reverse ? array_reverse($shipments) : $shipments;

            // Reference identifiers are always set: parseCreateResponse() falls back to pairing by
            // index when one is missing, which would quietly make the correlation test assert the
            // SDK's positional fallback instead of our own lookup.
            foreach ($ordered as $shipment) {
                $created[] = (new ShipmentDefsShipment())
                    ->setId(++$nextId)
                    ->setReferenceIdentifier((string) $shipment->getReferenceIdentifier());
            }

            return (new ShipmentResponsesPostShipmentsV12())
                ->setData((new ShipmentResponsesPostShipmentsV12Data())->setShipments($created));
        }
    );

    return $api;
}

/** @param array<string,ShipmentApi> $clientsByKey */
function makeExportService(array $clientsByKey, $chunkSize = null): ShipmentExportService
{
    $provider = Mockery::mock(ShipmentApiProvider::class);
    $provider->shouldReceive('userAgentVersion')->andReturn('5.9.0');
    $provider->shouldReceive('clientFor')->andReturnUsing(function (string $apiKey) use ($clientsByKey) {
        if (! isset($clientsByKey[$apiKey])) {
            throw new RuntimeException('no client configured for this key');
        }

        return $clientsByKey[$apiKey];
    });

    return new ShipmentExportService($provider, createConfig(['print/export_chunk_size' => $chunkSize]), new LabelPdfMerger());
}

/**
 * An api double that rejects the whole request with a 422, carrying the given body verbatim —
 * the shape the Core API spec allows for `errors` is what decides whether an error can be tied to
 * one order, so the tests supply real bodies rather than a message string.
 */
function makeRejectingShipmentApi(array $body, array &$calls): ShipmentApi
{
    $api = Mockery::mock(ShipmentApi::class);
    $api->shouldReceive('postShipments')->andReturnUsing(function (...$args) use ($body, &$calls) {
        $calls[] = array_map(
            static fn($s): string => (string) $s->getReferenceIdentifier(),
            postShipmentsRequestIn($args)->getData()->getShipments()
        );

        throw new ApiException(
            '[422] Client error: `POST https://api.myparcel.nl/shipments` resulted in a `422 (truncated...)',
            422,
            [],
            json_encode($body)
        );
    });

    return $api;
}

/**
 * Rejects the first call with $body, then behaves like the ordinary spy.
 *
 * This is the retry path: the API refuses a chunk, the service re-sends it without the orders the
 * response named, and that second call goes through.
 */
function makeRejectOnceShipmentApi(array $body, array &$calls): ShipmentApi
{
    $ordinary = makeShipmentApiSpy($calls);
    $attempts = 0;

    $api = Mockery::mock(ShipmentApi::class);
    $api->shouldReceive('postShipments')->andReturnUsing(
        function (...$args) use ($body, &$calls, $ordinary, &$attempts) {
            $attempts++;

            if (1 === $attempts) {
                $calls[] = array_map(
                    static fn($s): string => (string) $s->getReferenceIdentifier(),
                    postShipmentsRequestIn($args)->getData()->getShipments()
                );

                throw new ApiException('[422] Client error (truncated...)', 422, [], json_encode($body));
            }

            return $ordinary->postShipments(...$args);
        }
    );

    return $api;
}

/** A transport failure that says nothing about whether shipments were created. */
function makeTimingOutShipmentApi(array &$calls): ShipmentApi
{
    $api = Mockery::mock(ShipmentApi::class);
    $api->shouldReceive('postShipments')->andReturnUsing(function (...$args) use (&$calls) {
        $calls[] = array_map(
            static fn($s): string => (string) $s->getReferenceIdentifier(),
            postShipmentsRequestIn($args)->getData()->getShipments()
        );

        throw new ApiException('cURL error 28: Operation timed out', 0, [], null);
    });

    return $api;
}

/**
 * @param string|null $paperType what myparcelnl_magento_general/print/paper_type resolves to; null
 *                               for a store with no value, which reads as A6
 */
function makeLabelPositions(?string $paperType = null): LabelPositions
{
    return new LabelPositions(createConfig(['print/paper_type' => $paperType]));
}
