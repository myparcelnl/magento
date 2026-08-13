<?php

declare(strict_types=1);

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MyParcelNL\Magento\Plugin\Magento\Sales\OrderManagementStoreFilter;

/**
 * The plugin delegates the scope decision to the store-filtered OrderRepository, so these tests
 * assert on how the repository is used. Both intercepted methods run the same private guard, so each
 * case is driven over both method names rather than duplicated.
 *
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
    // ->with(117) is itself the assertion: the string '117' would not match the int expectation.
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
    // permittedStoreIds() === [] is not null, so the guard must run: the repository's own filter
    // forces store_id IN (-1), which never matches.
    $bag = makeOrderManagementFilter([]);
    $bag['repository']->shouldReceive('get')
        ->once()
        ->andThrow(NoSuchEntityException::singleField('entity_id', 117));

    $bag['plugin']->beforeGetCommentsList(Mockery::mock(OrderManagementInterface::class), 117);
})->throws(NoSuchEntityException::class);
