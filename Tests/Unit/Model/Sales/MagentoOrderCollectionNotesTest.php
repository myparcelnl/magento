<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\History\Collection as OrderStatusHistoryCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;

/**
 * The status-history comments an Order v1 export sends as order notes.
 *
 * One status-history query per chunk, not one per order.
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

function commentsFor(array $orders, array $history): array
{
    $historyCollection = fakeRows($history);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')
        ->with(OrderStatusHistoryCollection::class)
        ->andReturn($historyCollection);

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);

    return [invokePrivateMethod($collection, 'orderComments', [$orders]), $historyCollection];
}

it('groups every order\'s comments in one query, from the orders the chunk already holds', function () {
    // No order lookup by increment id: two stores can share one, and the chunk has the orders.
    [$comments, $history] = commentsFor(
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
        ->and($history->filters)->toBe(['parent_id' => ['in' => [11, 12]]]);
});

it('skips a history row that carries no comment', function () {
    [$comments] = commentsFor(
        [new Magento\Framework\DataObject(['entity_id' => 11, 'increment_id' => '100000001'])],
        [
            new Magento\Framework\DataObject(['parent_id' => 11, 'comment' => null]),
            new Magento\Framework\DataObject(['parent_id' => 11, 'comment' => 'kept']),
        ]
    );

    expect($comments)->toBe(['100000001' => ['kept']]);
});

it('asks nothing at all when the chunk is empty', function () {
    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->never();

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);

    expect(invokePrivateMethod($collection, 'orderComments', [[]]))->toBe([]);
});
