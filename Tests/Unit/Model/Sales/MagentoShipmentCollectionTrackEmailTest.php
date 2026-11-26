<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;
use MyParcelNL\Magento\Service\TrackTrace\TrackNumber;

/** @return int[] the ids of the shipments that got a track & trace mail */
function trackMailsSentFor(array $trackNumbersByShipmentId): array
{
    $mailed = [];
    $sender = Mockery::mock(TrackSender::class);
    $sender->shouldReceive('isEnabled')->andReturn(true);
    $sender->shouldReceive('send')->andReturnUsing(static function ($shipment) use (&$mailed): bool {
        $mailed[] = (int) $shipment->getId();

        return true;
    });

    $shipments = [];
    $tracks    = [];

    foreach ($trackNumbersByShipmentId as $shipmentId => $number) {
        $shipments[]         = createShipment(['getId' => $shipmentId, 'getEmailSent' => null]);
        $tracks[$shipmentId] = [createShipmentTrack(['getTrackNumber' => $number])];
    }

    $collection = newInstanceWithoutConstructor(MagentoShipmentCollection::class);
    setPrivateProperty($collection, 'trackSender', $sender);
    setPrivateProperty($collection, 'shipments', $shipments);
    setPrivateProperty($collection, 'trackMemo', $tracks);

    $collection->sendTrackEmailFromShipments();

    return $mailed;
}

it('mails only the shipments whose track got a real barcode', function () {
    expect(trackMailsSentFor([
        11 => '3SMYPA111',
        12 => TrackNumber::PRINTED,
        13 => TrackNumber::EMPTY,
    ]))->toBe([11]);
});
