<?php

declare(strict_types=1);

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Shipment\FulfilmentOrderBuilder;
use MyParcelNL\Magento\Service\Export\ExportErrorRecorder;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Helper\MyParcelCurl;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\Model\MyParcelRequest;

/**
 * What the admin is told when MyParcel does not confirm the orders of a PPS chunk.
 *
 * The real OrderCollection::save() runs against a fake cURL from MyParcelRequest::setCurlFactory(),
 * so these cases go through the SDK's own error handling, where a timeout and an error body are the
 * same exception.
 */

afterEach(function () {
    MyParcelRequest::setCurlFactory(null);
});

/** An order the real builder makes, with the given increment id as its external identifier. */
function ppsBuiltOrder(string $incrementId): FulfilmentOrder
{
    return createFulfilmentOrderBuilder()->build(
        createFulfilmentMagentoOrder(
            ['carrier' => CarrierPostNL::NAME, 'deliveryType' => 'standard'],
            ['getIncrementId' => $incrementId]
        ),
        []
    );
}

/**
 * Runs exportFulfilmentChunk() for one chunk, with every request answered by $answer.
 *
 * @param array<string,FulfilmentOrder> $builtByIncrementId what the builder returns per order
 * @param array{response?: string, code?: int, timeout?: string} $answer
 *
 * @return array{errors: string[], exported: string[], sent: string[], rows: array<string,string|null>}
 */
function ppsChunkRun(array $builtByIncrementId, array $answer): array
{
    $did = new class { public array $errors = []; public array $exported = []; public array $sent = []; public array $rows = []; };

    MyParcelRequest::setCurlFactory(static function () use ($answer, $did): MyParcelCurl {
        $curl = Mockery::mock(MyParcelCurl::class);
        $curl->shouldReceive('write')->andReturnUsing(
            static function ($method, $url, $headers = [], $body = '') use ($curl, $did) {
                $did->sent[] = (string) $body;

                return $curl;
            }
        );
        // MyParcelCurl::getResponse() throws the cURL error itself when curl_exec() fails.
        isset($answer['timeout'])
            ? $curl->shouldReceive('getResponse')->andThrow(new Exception($answer['timeout'], 28))
            : $curl->shouldReceive('getResponse')->andReturn(['response' => $answer['response'], 'headers' => [], 'code' => $answer['code']]);
        $curl->shouldReceive('close')->andReturnSelf();

        return $curl;
    });

    $messageManager = Mockery::mock(ManagerInterface::class);
    $messageManager->shouldReceive('addErrorMessage')->andReturnUsing(
        static function ($message) use ($messageManager, $did) {
            $did->errors[] = (string) $message;

            return $messageManager;
        }
    );

    $orderResource = Mockery::mock(OrderResource::class);
    $orderResource->shouldReceive('save')->andReturnUsing(
        static function (Order $order) use ($did): void {
            $did->exported[] = $order->getIncrementId();
        }
    );

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(OrderResource::class)->andReturn($orderResource);

    $errorRecorder = Mockery::mock(ExportErrorRecorder::class);
    $errorRecorder->shouldReceive('write')->andReturnUsing(
        static function (Order $order, ?string $error) use ($did): void {
            $did->rows[$order->getIncrementId()] = $error;
        }
    );

    $userAgent = Mockery::mock(UserAgent::class);
    $userAgent->shouldReceive('map')->andReturn([]);

    $builder       = Mockery::mock(FulfilmentOrderBuilder::class);
    $magentoOrders = [];

    foreach ($builtByIncrementId as $incrementId => $built) {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('getIncrementId')->andReturn((string) $incrementId);
        $order->shouldReceive('setData')->andReturnSelf();
        $order->shouldReceive('setIsInProcess')->andReturnSelf();
        $builder->shouldReceive('build')->with($order)->andReturn($built);
        $magentoOrders[] = $order;
    }

    $collection = Mockery::mock(MagentoOrderCollection::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('orderCommentsByIncrementId')->andReturn([]);
    setPrivateProperty($collection, 'messageManager', $messageManager);
    setPrivateProperty($collection, 'objectManager', $objectManager);
    setPrivateProperty($collection, 'userAgent', $userAgent);
    setPrivateProperty($collection, 'errorRecorder', $errorRecorder);
    setPrivateProperty($collection, 'options', []);

    (function () use ($builder, $magentoOrders): void {
        $this->exportFulfilmentChunk($builder, $magentoOrders);
    })->call($collection);

    return ['errors' => $did->errors, 'exported' => $did->exported, 'sent' => $did->sent, 'rows' => $did->rows];
}

it('tells the admin that a chunk MyParcel did not answer may have been created', function () {
    $run = ppsChunkRun(
        ['100000001' => ppsBuiltOrder('100000001'), '100000002' => ppsBuiltOrder('100000002')],
        ['timeout' => 'Operation timed out after 10000 milliseconds']
    );

    expect($run['exported'])->toBe([])
        ->and($run['errors'])->toHaveCount(1)
        ->and($run['errors'][0])->toContain('MyParcel did not confirm these orders, so it is unknown whether it created them: 100000001, 100000002.')
        ->and($run['rows'])->toBe(['100000001' => $run['errors'][0], '100000002' => $run['errors'][0]])
        ->and($run['errors'][0])->toContain('Operation timed out');
});

it('does not mark an order exported when the answer leaves it out', function () {
    // A 5xx without a body throws nothing in the SDK: save() returns an empty collection.
    $run = ppsChunkRun(
        ['100000001' => ppsBuiltOrder('100000001'), '100000002' => ppsBuiltOrder('100000002')],
        ['response' => '', 'code' => 502]
    );

    expect($run['exported'])->toBe([])
        ->and($run['errors'])->toHaveCount(1)
        ->and($run['errors'][0])->toContain('MyParcel did not confirm these orders, so it is unknown whether it created them: 100000001, 100000002.')
        ->and($run['rows'])->toBe(['100000001' => $run['errors'][0], '100000002' => $run['errors'][0]]);
});
