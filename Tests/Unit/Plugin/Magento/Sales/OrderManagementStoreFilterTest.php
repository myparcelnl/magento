<?php

declare(strict_types=1);

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Plugin\Magento\Sales\OrderManagementStoreFilter;

/**
 * @param int[]|null $permitted
 */
function makeOrderManagementFilter(?array $permitted): array
{
    $repository = Mockery::mock(OrderRepositoryInterface::class);

    return [
        'plugin'     => new OrderManagementStoreFilter(mockTokenScopeContext($permitted), $repository),
        'repository' => $repository,
    ];
}

it('does not load the order at all when no token authenticated the request', function (string $method) {
    $bag = makeOrderManagementFilter(null);
    $bag['repository']->shouldNotReceive('get');

    expect($bag['plugin']->{$method}(Mockery::mock(OrderManagementInterface::class), 117))->toBeNull();
})->with(['beforeGetCommentsList', 'beforeGetStatus']);

it('resolves the order through the store-filtered repository, casting the id to int', function (string $method) {
    $bag = makeOrderManagementFilter([3]);
    // ->with(117) is the assertion: the string '117' would not match it.
    $bag['repository']->shouldReceive('get')->once()->with(117)->andReturn(Mockery::mock(OrderInterface::class));

    expect($bag['plugin']->{$method}(Mockery::mock(OrderManagementInterface::class), '117'))->toBeNull();
})->with(['beforeGetCommentsList', 'beforeGetStatus']);

it('propagates the repository 404 for an out-of-scope order', function (string $method) {
    $bag = makeOrderManagementFilter([3]);
    $bag['repository']->shouldReceive('get')
        ->once()
        ->andThrow(NoSuchEntityException::singleField('entity_id', 117));

    $bag['plugin']->{$method}(Mockery::mock(OrderManagementInterface::class), 117);
})->with(['beforeGetCommentsList', 'beforeGetStatus'])->throws(NoSuchEntityException::class);

it('still consults the repository when the token owns no stores', function () {
    // [] is not null, so the guard must still run.
    $bag = makeOrderManagementFilter([]);
    $bag['repository']->shouldReceive('get')
        ->once()
        ->andThrow(NoSuchEntityException::singleField('entity_id', 117));

    $bag['plugin']->beforeGetCommentsList(Mockery::mock(OrderManagementInterface::class), 117);
})->throws(NoSuchEntityException::class);
