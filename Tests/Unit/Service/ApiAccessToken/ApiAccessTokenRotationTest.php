<?php

declare(strict_types=1);

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

// ====================================================================
// Scenario 1 — rotation at the same coordinate overwrites the row atomically.
// ====================================================================

it('rotation at the same scope coordinate overwrites the hash row in place', function (string $scope, int $scopeId) {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();

    $t1 = $service->generateForScope($scope, $scopeId);
    expect($harness->rows)->toHaveCount(1);
    expect($harness->valueAt($scope, $scopeId))->toBe(hash('sha256', $t1));

    $t2 = $service->generateForScope($scope, $scopeId);

    expect($t2)->not->toBe($t1);
    expect($harness->rows)->toHaveCount(1);
    expect($harness->valueAt($scope, $scopeId))->toBe(hash('sha256', $t2));
    expect($harness->valueAt($scope, $scopeId))->not->toBe(hash('sha256', $t1));
})->with([
    'default scope' => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
    'website scope' => [ScopeInterface::SCOPE_WEBSITES, 1],
    'store scope'   => [ScopeInterface::SCOPE_STORES, 2],
]);

// ====================================================================
// Scenario 2 — the previous token is rejected and the new one authenticates
// at the same scope coordinate. Covers the rotation effect on the REST entry point.
// ====================================================================

it('after rotation the previous token is rejected and the new one authenticates at the same scope', function (string $scope, int $scopeId) {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $t1 = $service->generateForScope($scope, $scopeId);
    $t2 = $service->generateForScope($scope, $scopeId);

    [$oldCtx] = $harness->authenticate('MyParcel ' . $t1, $stores);
    expect($oldCtx->getUserType())->toBeNull();
    expect($oldCtx->getUserId())->toBeNull();

    [$newCtx, $newScope] = $harness->authenticate('MyParcel ' . $t2, $stores);
    expect($newCtx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($newCtx->getUserId())->toBe(MyParcelTokenLifecycleHarness::INTEGRATION_ID);
    expect($newScope->getOwner())->toBe(['scope' => $scope, 'scopeId' => $scopeId]);
})->with([
    'default scope' => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
    'website scope' => [ScopeInterface::SCOPE_WEBSITES, 1],
    'store scope'   => [ScopeInterface::SCOPE_STORES, 2],
]);

// ====================================================================
// Scenario 3 — rotation preserves the partition of permitted stores
// (ownership is row-coordinate, not hash-equality, so the new token sees the same stores).
// ====================================================================

it('rotation at default scope preserves the partition of permitted stores', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [
        ['id' => 1, 'websiteId' => 1],
        ['id' => 2, 'websiteId' => 1],
        ['id' => 3, 'websiteId' => 2],
    ];

    $t1 = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    [, $beforeScope] = $harness->authenticate('MyParcel ' . $t1, $stores);
    $before = $beforeScope->permittedStoreIds();

    $t2 = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    [, $afterScope] = $harness->authenticate('MyParcel ' . $t2, $stores);

    expect($afterScope->permittedStoreIds())->toBe($before);
    expect($afterScope->permittedStoreIds())->toBe([1, 2, 3]);
});

// ====================================================================
// Scenario 4a — cross-scope isolation: rotating the default token does not
// touch a store-scoped token (or its partition carve-out).
// ====================================================================

it('rotating the default-scope token does not affect a store-scoped token', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tStore2  = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    $tDefault2 = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    [$oldDef] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($oldDef->getUserType())->toBeNull();

    [$newDef, $newDefScope] = $harness->authenticate('MyParcel ' . $tDefault2, $stores);
    expect($newDef->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($newDefScope->permittedStoreIds())->toBe([1]);

    [$s2Ctx, $s2Scope] = $harness->authenticate('MyParcel ' . $tStore2, $stores);
    expect($s2Ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($s2Scope->getOwner())->toBe(['scope' => ScopeInterface::SCOPE_STORES, 'scopeId' => 2]);
    expect($s2Scope->permittedStoreIds())->toBe([2]);
});

// ====================================================================
// Scenario 4b — cross-scope isolation: rotating a store-scoped token does not
// touch the default token (or its partition).
// ====================================================================

it('rotating a store-scoped token does not affect the default-scope token', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tStore2  = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);
    $defaultRowBefore = $harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    $tStore2Rotated = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    expect($harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0))->toBe($defaultRowBefore);

    [$oldS2] = $harness->authenticate('MyParcel ' . $tStore2, $stores);
    expect($oldS2->getUserType())->toBeNull();

    [$newS2, $newS2Scope] = $harness->authenticate('MyParcel ' . $tStore2Rotated, $stores);
    expect($newS2->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($newS2Scope->permittedStoreIds())->toBe([2]);

    [$defCtx, $defScope] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($defCtx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($defScope->permittedStoreIds())->toBe([1]);
});

// ====================================================================
// Scenario 5 — native Bearer scheme is left to Magento's native chain
// regardless of rotation state on the MyParcel scheme.
// ====================================================================

it('the Bearer scheme is not intercepted by the MyParcel UserContext after a rotation', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $service = $harness->service();

    $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    [$ctx] = $harness->authenticate('Bearer some-admin-token', [['id' => 1, 'websiteId' => 1]]);

    expect($ctx->getUserType())->toBeNull();
    expect($ctx->getUserId())->toBeNull();
});

// ====================================================================
// Hash-collision on rotation (TR-000004 hash-uniqueness invariant, US-000002 tech note).
// Asserts the 409 defence applies just as much to a rotation as to a first generate,
// and that the existing row at the rotated coordinate is left intact.
// ====================================================================

it('a rotation that would collide with another scope\'s hash row is rejected and persists nothing', function () {
    $harness = new MyParcelTokenLifecycleHarness();

    // Pre-seed a default-scope row whose hash matches the forced-bytes seed.
    $forcedBytes   = str_repeat("\x01", 32);
    $collidingHash = hash('sha256', bin2hex($forcedBytes));
    $harness->save(
        TokenService::CONFIG_PATH,
        $collidingHash,
        ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        0
    );

    // First, plant a real (random) token at stores:2.
    $original  = $harness->service()->generateForScope(ScopeInterface::SCOPE_STORES, 2);
    $originalHash = hash('sha256', $original);
    expect($harness->valueAt(ScopeInterface::SCOPE_STORES, 2))->toBe($originalHash);

    // Now rotate at stores:2 with bytes that would force the colliding hash.
    $forcedService = $harness->service($forcedBytes);
    expect(fn () => $forcedService->generateForScope(ScopeInterface::SCOPE_STORES, 2))
        ->toThrow(AlreadyExistsException::class);

    // The rotation didn't persist — stores:2 still holds the original token's hash.
    expect($harness->valueAt(ScopeInterface::SCOPE_STORES, 2))->toBe($originalHash);
    expect($harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0))->toBe($collidingHash);
});
