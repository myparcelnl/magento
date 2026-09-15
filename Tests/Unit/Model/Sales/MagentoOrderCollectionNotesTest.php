<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\History\Collection as OrderStatusHistoryCollection;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;

/**
 * The status-history comments a PPS export sends as order notes.
 *
 * Two queries for the whole batch. It used to be a loadByIncrementId() plus a history collection
 * per order, so a chunk of twenty cost forty.
 */
function fakeRows(array $rows): object
{
    return new class($rows) implements IteratorAggregate {
        private array $rows;
        public array  $filters = [];

        public function __construct(array $rows)
        {
            $this->rows = $rows;
        }

        public function addFieldToFilter($field, $condition = null): self
        {
            $this->filters[$field] = $condition;

            return $this;
        }

        public function setOrder($field, $direction = 'DESC'): self
        {
            return $this;
        }

        public function getIterator(): Iterator
        {
            return new ArrayIterator($this->rows);
        }
    };
}

function commentsFor(array $incrementIds, array $orders, array $history): array
{
    $orderCollection   = fakeRows($orders);
    $historyCollection = fakeRows($history);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')
        ->with(MagentoCollection::PATH_MODEL_ORDER_COLLECTION)
        ->andReturn($orderCollection);
    $objectManager->shouldReceive('create')
        ->with(OrderStatusHistoryCollection::class)
        ->andReturn($historyCollection);

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);

    return [
        invokePrivateMethod($collection, 'orderCommentsByIncrementId', [$incrementIds]),
        $orderCollection,
        $historyCollection,
    ];
}

it('groups every order\'s comments in two queries, not two per order', function () {
    [$comments, $orders, $history] = commentsFor(
        ['100000001', '100000002'],
        [
            new Magento\Framework\DataObject(['entity_id' => 11, 'increment_id' => '100000001']),
            new Magento\Framework\DataObject(['entity_id' => 12, 'increment_id' => '100000002']),
        ],
        [
            new Magento\Framework\DataObject(['parent_id' => 11, 'comment' => 'first']),
            new Magento\Framework\DataObject(['parent_id' => 12, 'comment' => 'second']),
            new Magento\Framework\DataObject(['parent_id' => 11, 'comment' => 'third']),
        ]
    );

    expect($comments)->toBe([
        '100000001' => ['first', 'third'],
        '100000002' => ['second'],
    ])
        ->and($orders->filters)->toBe(['increment_id' => ['in' => ['100000001', '100000002']]])
        ->and($history->filters)->toBe(['parent_id' => ['in' => [11, 12]]]);
});

it('skips a history row that carries no comment', function () {
    [$comments] = commentsFor(
        ['100000001'],
        [new Magento\Framework\DataObject(['entity_id' => 11, 'increment_id' => '100000001'])],
        [
            new Magento\Framework\DataObject(['parent_id' => 11, 'comment' => null]),
            new Magento\Framework\DataObject(['parent_id' => 11, 'comment' => 'kept']),
        ]
    );

    expect($comments)->toBe(['100000001' => ['kept']]);
});

it('asks nothing at all when the batch is empty', function () {
    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->never();

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);

    expect(invokePrivateMethod($collection, 'orderCommentsByIncrementId', [[]]))->toBe([]);
});
