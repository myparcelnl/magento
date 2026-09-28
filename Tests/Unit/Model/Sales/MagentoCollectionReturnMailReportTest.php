<?php

declare(strict_types=1);

use Magento\Framework\Message\ManagerInterface;
use MyParcelNL\Magento\Service\Export\ShipmentExportService;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\FixedShipmentRecipient;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsShipment;

/**
 * What the admin is told after asking for return-label mails.
 *
 * A lookup that failed leaves its shipments out of the answer, which is shaped exactly like
 * "nothing to do". The run then made no return label at all and still reported success.
 *
 * @param array{shipments: array<int,object>, errors: string[]} $lookup
 * @param string[]                                              $createReturnsErrors
 * @param array<string,array<int,int>>                          $returns per key, return id => parent id
 * @param array<int,\Magento\Sales\Model\Order\Shipment\Track[]>   $tracks  keyed by Magento shipment id
 * @param int[]                                                 $ids     MyParcel shipment ids of key-a
 * @param object[]                                              $shipments Magento shipments in the collection
 *
 * @return array{sent: bool, errors: string[], successes: int, rows: array}
 */
function returnMailRun(
    array $lookup,
    array $createReturnsErrors = [],
    array $returns = [],
    array $tracks = [],
    array $ids = [111],
    array $shipments = []
): array
{
    $recorded = ['errors' => [], 'successes' => 0];

    $messageManager = Mockery::mock(ManagerInterface::class);
    $messageManager->shouldReceive('addErrorMessage')->andReturnUsing(
        static function ($message) use (&$recorded): void {
            $recorded['errors'][] = (string) $message;
        }
    );
    $messageManager->shouldReceive('addSuccessMessage')->andReturnUsing(
        static function () use (&$recorded): void {
            $recorded['successes']++;
        }
    );

    $rows = [];

    $exportService = Mockery::mock(ShipmentExportService::class);
    $exportService->shouldReceive('fetchLatestWithErrors')->andReturn($lookup);
    $exportService->shouldReceive('createReturns')->andReturnUsing(
        static function (array $given) use (&$rows, $createReturnsErrors, $returns): array {
            $rows = $given;

            return ['returns' => $returns, 'errors' => $createReturnsErrors];
        }
    );

    $collection = partialOrderCollection($shipments);
    $collection->shouldReceive('getMyparcelConsignmentIdsByApiKey')->andReturn(['key-a' => $ids]);
    $collection->shouldReceive('tracksByShipmentId')->andReturn($tracks);
    setPrivateProperty($collection, 'exportService', $exportService);
    setPrivateProperty($collection, 'messageManager', $messageManager);

    $sent = $collection->sendReturnLabelMails();

    return ['sent' => $sent, 'rows' => $rows] + $recorded;
}

/**
 * The real type fetchLatest() answers with, not a double.
 *
 * A hand-rolled double let sendReturnLabelMails() call getCarrier(), which this class does not
 * have — the export builds a different shipment class that does. Building the real one is what
 * makes that a test failure rather than a fatal in the admin.
 */
/** A Magento shipment of the given order, as the collection iterates it. */
function returnMailMagentoShipment(int $id, string $incrementId): object
{
    $order = Mockery::mock(\Magento\Sales\Model\Order::class);
    $order->shouldReceive('getIncrementId')->andReturn($incrementId);

    $shipment = Mockery::mock(\Magento\Sales\Model\Order\Shipment::class);
    $shipment->shouldReceive('getId')->andReturn($id);
    $shipment->shouldReceive('getOrder')->andReturn($order);

    return $shipment;
}

/** A track of Magento shipment $parentId, as the database holds it. */
function returnMailTrack(int $myParcelId, int $parentId, string $barcode = ''): \Magento\Sales\Model\Order\Shipment\Track
{
    $track = exportTrack($myParcelId);
    $track->setData('parent_id', $parentId);
    $track->setData('track_number', $barcode);

    return $track;
}

function returnMailShipment(int $id = 111, int $carrierId = 1): ShipmentDefsShipment
{
    return (new ShipmentDefsShipment())
        ->setId($id)
        ->setCarrierId($carrierId)
        ->setBarcode('BARCODE' . $id)
        ->setRecipient(new FixedShipmentRecipient([
            'person' => 'Test Person',
            'email'  => 'test@example.test',
        ]));
}

it('shows the lookup error and reports nothing sent when the account is unreachable', function () {
    $run = returnMailRun(['shipments' => [], 'errors' => ['Could not reach the MyParcel API']]);

    expect($run['sent'])->toBeFalse()
        ->and($run['errors'])->toBe(['Could not reach the MyParcel API'])
        ->and($run['successes'])->toBe(0);
});

it('still says something when the lookup answered nothing and named no error', function () {
    // Silence here reads as success to the caller, which is the whole defect.
    $run = returnMailRun(['shipments' => [], 'errors' => []]);

    expect($run['sent'])->toBeFalse()
        ->and($run['errors'])->toHaveCount(1);
});

it('names the order and barcode of a shipment the account behind the key does not know', function () {
    // The API answers another account's shipment id with an empty list, not with an error.
    $track = returnMailTrack(111, 5, 'BARCODE111');

    $run = returnMailRun(
        ['shipments' => [], 'errors' => []],
        [],
        [],
        [5 => [$track]],
        [111],
        [returnMailMagentoShipment(5, '000000001')]
    );

    expect($run['sent'])->toBeFalse()
        ->and($run['errors'])->toBe([
            '000000001: MyParcel does not know shipment BARCODE111 for the API key of this store.',
        ]);
});

it('names only the unknown shipment and still returns the one the account knows', function () {
    $known   = returnMailTrack(111, 5);
    $unknown = returnMailTrack(222, 6, 'BARCODE222');

    $run = returnMailRun(
        ['shipments' => [111 => returnMailShipment()], 'errors' => []],
        [],
        [],
        [5 => [$known], 6 => [$unknown]],
        [111, 222],
        [returnMailMagentoShipment(5, '000000001'), returnMailMagentoShipment(6, '000000002')]
    );

    expect($run['sent'])->toBeTrue()
        ->and($run['rows']['key-a'])->toHaveCount(1)
        ->and($run['errors'])->toBe([
            '000000002: MyParcel does not know shipment BARCODE222 for the API key of this store.',
        ]);
});

it('reports sent when a return was created', function () {
    $run = returnMailRun(['shipments' => [111 => returnMailShipment()], 'errors' => []]);

    expect($run['sent'])->toBeTrue()
        ->and($run['errors'])->toBe([]);
});

it('reports nothing sent when every account refused the return', function () {
    $run = returnMailRun(
        ['shipments' => [111 => returnMailShipment()], 'errors' => []],
        ['The return shipment was refused']
    );

    expect($run['sent'])->toBeFalse()
        ->and($run['errors'])->toBe(['The return shipment was refused']);
});

it('carries the email and name the API requires on a return row', function () {
    // Acceptance refuses a row of parent + carrier alone and accepts the same row with email and
    // name, so both are mandatory. They come off the parent's recipient: same person, other way.
    $run = returnMailRun(['shipments' => [111 => returnMailShipment()], 'errors' => []]);

    expect($run['rows']['key-a'][0])->toBe([
        'parent'               => 111,
        'reference_identifier' => 111,
        'carrier'              => 1,
        'email'                => 'test@example.test',
        'name'                 => 'Test Person',
    ]);
});

it('records the return shipment on the track of its parent', function () {
    $track = exportTrack(111, 1, $saves);

    returnMailRun(
        ['shipments' => [111 => returnMailShipment()], 'errors' => []],
        [],
        ['key-a' => [901 => 111]],
        [5 => [$track]]
    );

    expect($track->getData('myparcel_return_ids'))->toBe('[901]')
        ->and($saves)->toBe(1);
});

it('adds a second return mail to the ones already recorded', function () {
    $track = exportTrack(111, 1, $saves);
    $track->setData('myparcel_return_ids', '[901]');

    returnMailRun(
        ['shipments' => [111 => returnMailShipment()], 'errors' => []],
        [],
        ['key-a' => [902 => 111]],
        [5 => [$track]]
    );

    expect($track->getData('myparcel_return_ids'))->toBe('[901,902]')
        ->and($saves)->toBe(1);
});

it('skips a return whose parent has no track, and still reports it sent', function () {
    mockLoggerFacade()->shouldReceive('warning')->once();
    $track = exportTrack(222, 1, $saves);

    $run = returnMailRun(
        ['shipments' => [111 => returnMailShipment()], 'errors' => []],
        [],
        ['key-a' => [901 => 111]],
        [5 => [$track]]
    );

    expect($run['sent'])->toBeTrue()
        ->and($track->getData('myparcel_return_ids'))->toBeNull()
        ->and($saves)->toBe(0);
});

it('leaves out a shipment whose carrier has no returns, and still returns the others', function () {
    // UPS (8) is not in the return endpoint's carrier enum; the SDK throws on it, which used to
    // cost every other shipment of that account its return label too.
    $run = returnMailRun(
        ['shipments' => [111 => returnMailShipment(), 222 => returnMailShipment(222, 8)], 'errors' => []],
        [],
        [],
        [5 => [returnMailTrack(111, 5)], 6 => [returnMailTrack(222, 6)]],
        [111, 222],
        [returnMailMagentoShipment(5, '000000001'), returnMailMagentoShipment(6, '000000002')]
    );

    expect($run['sent'])->toBeTrue()
        ->and($run['rows']['key-a'])->toHaveCount(1)
        ->and($run['rows']['key-a'][0]['parent'])->toBe(111)
        ->and($run['errors'])->toHaveCount(1)
        ->and($run['errors'][0])->toStartWith('000000002: ')
        ->and($run['errors'][0])->toContain('BARCODE222');
});

it('names only the unsupported carrier when no shipment can get a return', function () {
    $run = returnMailRun(
        ['shipments' => [111 => returnMailShipment(111, 8)], 'errors' => []],
        [],
        [],
        [5 => [returnMailTrack(111, 5)]],
        [111],
        [returnMailMagentoShipment(5, '000000001')]
    );

    expect($run['sent'])->toBeFalse()
        ->and($run['rows'])->toBe([])
        ->and($run['errors'])->toBe(['000000001: The carrier of shipment BARCODE111 does not support return labels.']);
});
