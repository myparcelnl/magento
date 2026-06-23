<?php

declare(strict_types=1);

use Magento\Framework\Webapi\Authorization;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;
use MyParcelNL\Magento\Plugin\Magento\Webapi\Rest\RequestValidator\MyParcelTokenAclGate;
use MyParcelNL\Magento\Service\ScopedResourceRegistry;

function makeAclGate(?array $owner, array $registry): MyParcelTokenAclGate
{
    $context = Mockery::mock(TokenScopeContext::class);
    $context->shouldReceive('getOwner')->andReturn($owner);

    return new MyParcelTokenAclGate($context, new ScopedResourceRegistry($registry));
}

function proceedReturning(bool $value): callable
{
    return function () use ($value): bool {
        return $value;
    };
}

it('passes through to native authorization when no token authenticated this request', function () {
    $gate    = makeAclGate(null, []);
    $subject = Mockery::mock(Authorization::class);

    $called = false;
    $proceed = function ($resources, $userId) use (&$called) {
        $called = true;
        return true;
    };

    $result = $gate->aroundIsAllowed($subject, $proceed, ['Magento_Customer::manage'], null);

    expect($called)->toBeTrue();
    expect($result)->toBeTrue();
});

it('preserves a native deny when no token authenticated this request', function () {
    $gate    = makeAclGate(null, []);
    $subject = Mockery::mock(Authorization::class);

    $result = $gate->aroundIsAllowed($subject, proceedReturning(false), ['Magento_Customer::manage'], null);

    expect($result)->toBeFalse();
});

it('passes through to native authorization when every requested resource is in the registry', function () {
    $gate    = makeAclGate(['scope' => 'default', 'scopeId' => 0], [
        'Magento_Sales::actions_view',
        'MyParcelNL_Magento::delivery_options_read',
    ]);
    $subject = Mockery::mock(Authorization::class);

    $called = false;
    $proceed = function ($resources, $userId) use (&$called) {
        $called = true;
        return true;
    };

    $result = $gate->aroundIsAllowed(
        $subject,
        $proceed,
        ['Magento_Sales::actions_view'],
        42
    );

    expect($called)->toBeTrue();
    expect($result)->toBeTrue();
});

it('denies (returns false) without proceeding when a single requested resource is not in the registry', function () {
    $gate    = makeAclGate(['scope' => 'default', 'scopeId' => 0], ['Magento_Sales::actions_view']);
    $subject = Mockery::mock(Authorization::class);

    $called = false;
    $proceed = function () use (&$called) {
        $called = true;
        return true;
    };

    $result = $gate->aroundIsAllowed($subject, $proceed, 'Magento_Customer::manage', 42);

    expect($result)->toBeFalse();
    expect($called)->toBeFalse();
});

it('denies when at least one of multiple requested resources is not in the registry', function () {
    $gate    = makeAclGate(['scope' => 'stores', 'scopeId' => 2], [
        'Magento_Sales::actions_view',
    ]);
    $subject = Mockery::mock(Authorization::class);

    $called = false;
    $proceed = function () use (&$called) {
        $called = true;
        return true;
    };

    $result = $gate->aroundIsAllowed(
        $subject,
        $proceed,
        ['Magento_Sales::actions_view', 'MyParcelNL_Magento::experimental'],
        42
    );

    expect($result)->toBeFalse();
    expect($called)->toBeFalse();
});

it('still defers to native authorization (which may itself deny) for token callers when the registry covers every resource', function () {
    $gate    = makeAclGate(['scope' => 'default', 'scopeId' => 0], ['Magento_Sales::actions_view']);
    $subject = Mockery::mock(Authorization::class);

    $result = $gate->aroundIsAllowed(
        $subject,
        proceedReturning(false),
        ['Magento_Sales::actions_view'],
        42
    );

    expect($result)->toBeFalse();
});
