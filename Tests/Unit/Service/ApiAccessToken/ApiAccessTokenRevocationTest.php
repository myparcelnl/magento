<?php

declare(strict_types=1);

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\InputException;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;

// ====================================================================
// Scenario 1 — after revoke at scope S, the previously-issued token is rejected.
// ====================================================================

it('after revocation the previous token is rejected at the same scope', function (string $scope, int $scopeId) {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $t1 = $service->generateForScope($scope, $scopeId);
    $service->revokeForScope($scope, $scopeId);

    expect($harness->valueAt($scope, $scopeId))->toBeNull();

    [$ctx] = $harness->authenticate('MyParcel ' . $t1, $stores);
    expect($ctx->getUserType())->toBeNull();
    expect($ctx->getUserId())->toBeNull();
})->with([
    'default scope' => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
    'website scope' => [ScopeInterface::SCOPE_WEBSITES, 1],
    'store scope'   => [ScopeInterface::SCOPE_STORES, 2],
]);

// ====================================================================
// Scenario 2a — revoking a store-scoped token releases its store back to the default
// token (which is the next-coarsest tier here, no website token in play),
// and the old store-scoped token is rejected.
// ====================================================================

it('revoking a store-scoped token releases its store back to the default token', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tStore2  = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    [, $defBefore] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($defBefore->permittedStoreIds())->toBe([1]);

    $service->revokeForScope(ScopeInterface::SCOPE_STORES, 2);

    [$oldS2] = $harness->authenticate('MyParcel ' . $tStore2, $stores);
    expect($oldS2->getUserType())->toBeNull();

    [$defCtx, $defAfter] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($defCtx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($defAfter->permittedStoreIds())->toBe([1, 2]);
});

// ====================================================================
// Scenario 2b — revoking the default-scope token does not affect a store-scoped token.
// ====================================================================

it('revoking the default-scope token does not affect a store-scoped token', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tStore2  = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    $service->revokeForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    [$oldDef] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($oldDef->getUserType())->toBeNull();

    [$s2Ctx, $s2Scope] = $harness->authenticate('MyParcel ' . $tStore2, $stores);
    expect($s2Ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($s2Scope->getOwner())->toBe(['scope' => ScopeInterface::SCOPE_STORES, 'scopeId' => 2]);
    expect($s2Scope->permittedStoreIds())->toBe([2]);
});

// ====================================================================
// Scenario 3b — cascade-back into the website tier: with default + website + store rows,
// revoking the store releases that store to the website token (NOT the default).
// ====================================================================

it('cascade-back: revoking a store rejoins it to the parent website token, not default', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault  = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tWebsite1 = $service->generateForScope(ScopeInterface::SCOPE_WEBSITES, 1);
    $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    [, $wBefore] = $harness->authenticate('MyParcel ' . $tWebsite1, $stores);
    expect($wBefore->permittedStoreIds())->toBe([1]);

    $service->revokeForScope(ScopeInterface::SCOPE_STORES, 2);

    [$wCtx, $wAfter] = $harness->authenticate('MyParcel ' . $tWebsite1, $stores);
    expect($wCtx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($wAfter->permittedStoreIds())->toBe([1, 2]);

    [, $defScope] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($defScope->permittedStoreIds())->toBe([]);
});

// ====================================================================
// Scenario 3c — revoking a website-scoped token releases its non-store-tokened stores
// to the default token, but stores still owned by their own token stay carved out.
// ====================================================================

it('cascade-back: revoking a website rejoins its non-store-tokened stores to default', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault  = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tWebsite1 = $service->generateForScope(ScopeInterface::SCOPE_WEBSITES, 1);
    $tStore2   = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    $service->revokeForScope(ScopeInterface::SCOPE_WEBSITES, 1);

    [$oldW] = $harness->authenticate('MyParcel ' . $tWebsite1, $stores);
    expect($oldW->getUserType())->toBeNull();

    [$defCtx, $defScope] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($defCtx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($defScope->permittedStoreIds())->toBe([1]);

    [, $s2Scope] = $harness->authenticate('MyParcel ' . $tStore2, $stores);
    expect($s2Scope->permittedStoreIds())->toBe([2]);
});

// ====================================================================
// Scenario 4 — after a revoke, regenerating at the same scope issues a fresh token
// that authenticates with the original owner coordinate.
// ====================================================================

it('regenerating after a revoke issues a fresh token at the same scope', function (string $scope, int $scopeId) {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $service->generateForScope($scope, $scopeId);
    $service->revokeForScope($scope, $scopeId);
    $tFresh = $service->generateForScope($scope, $scopeId);

    [$ctx, $scopeCtx] = $harness->authenticate('MyParcel ' . $tFresh, $stores);
    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($scopeCtx->getOwner())->toBe(['scope' => $scope, 'scopeId' => $scopeId]);
})->with([
    'default scope' => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
    'website scope' => [ScopeInterface::SCOPE_WEBSITES, 1],
    'store scope'   => [ScopeInterface::SCOPE_STORES, 2],
]);

// ====================================================================
// Scenario 5 — native Bearer scheme is left to Magento's native chain
// regardless of revocation state on the MyParcel scheme.
// ====================================================================

it('the Bearer scheme is not intercepted by the MyParcel UserContext after a revoke', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();

    $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $service->revokeForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    [$ctx] = $harness->authenticate('Bearer some-admin-token', [['id' => 1, 'websiteId' => 1]]);
    expect($ctx->getUserType())->toBeNull();
    expect($ctx->getUserId())->toBeNull();
});

// ====================================================================
// TokenService::revokeForScope unit checks — scope validation, scopeId normalization,
// idempotency, cache flush.
// ====================================================================

it('revokeForScope rejects an unsupported scope', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();

    expect(fn () => $service->revokeForScope('group', 1))->toThrow(InputException::class);
});

it('revokeForScope is idempotent when no row exists at the coordinate', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();

    $service->revokeForScope(ScopeInterface::SCOPE_STORES, 7);
    expect($harness->rows)->toBe([]);
});

it('revokeForScope forces scopeId to 0 for the default scope', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();

    $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    expect($harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0))->not->toBeNull();

    // Passing an arbitrary non-zero scopeId for default must still target the (default, 0) row.
    $service->revokeForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 99);
    expect($harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0))->toBeNull();
});
