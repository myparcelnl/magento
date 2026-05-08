<?php

declare(strict_types=1);

use Magento\Authorization\Model\UserContextInterface;
use Magento\Config\Model\ResourceModel\Config\Data\Collection as ConfigDataCollection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Model\Integration;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Authorization\ApiAccessTokenUserContext;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

const FAKE_INTEGRATION_ID = 7;

function makeRequestWithAuthorization(?string $value): RequestInterface
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getHeader')->with('Authorization')->andReturn($value);
    return $request;
}

/**
 * @param array<int, array{scope: string, scope_id: int, value: string}> $rows
 */
function makeUserContextCollectionFactory(array $rows): CollectionFactory
{
    $items = array_map(static fn (array $row) => new DataObject($row), $rows);

    $collection = Mockery::mock(ConfigDataCollection::class);
    $collection->shouldReceive('addFieldToFilter')->andReturnSelf();
    $collection->shouldReceive('getItems')->andReturn($items);

    $factory = Mockery::mock(CollectionFactory::class);
    $factory->shouldReceive('create')->andReturn($collection);
    return $factory;
}

function makeIntegrationService(?int $id): IntegrationServiceInterface
{
    $integration = Mockery::mock(Integration::class);
    $integration->shouldReceive('getId')->andReturn($id);

    $service = Mockery::mock(IntegrationServiceInterface::class);
    $service->shouldReceive('findByName')
        ->with(ApiAccessTokenUserContext::INTEGRATION_NAME)
        ->andReturn($integration);
    return $service;
}

function makeContext(): TokenScopeContext
{
    return Mockery::mock(TokenScopeContext::class)->shouldIgnoreMissing();
}

it('returns null userId/userType when no Authorization header is present', function () {
    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization(null),
        makeUserContextCollectionFactory([]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null userId/userType when scheme is Bearer (so the native chain handles it)', function () {
    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization('Bearer some-magento-admin-token'),
        makeUserContextCollectionFactory([]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null when MyParcel scheme is present but the token is empty', function () {
    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization('MyParcel '),
        makeUserContextCollectionFactory([]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null when no row hashes the presented token', function () {
    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization('MyParcel deadbeef'),
        makeUserContextCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', 'a-different-token')],
        ]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('matches a default-scope row and returns USER_TYPE_INTEGRATION + integration id', function () {
    $plaintext = 'plaintext-token-1';
    $context   = Mockery::mock(TokenScopeContext::class);
    $context->shouldReceive('setOwner')
        ->once()
        ->with(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization('MyParcel ' . $plaintext),
        makeUserContextCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        $context
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($ctx->getUserId())->toBe(FAKE_INTEGRATION_ID);
});

it('matches a website-scope row and propagates the (websites, id) coordinate', function () {
    $plaintext = 'plaintext-token-2';
    $context   = Mockery::mock(TokenScopeContext::class);
    $context->shouldReceive('setOwner')
        ->once()
        ->with(ScopeInterface::SCOPE_WEBSITES, 1);

    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization('MyParcel ' . $plaintext),
        makeUserContextCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', 'unrelated')],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1, 'value' => hash('sha256', $plaintext)],
        ]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        $context
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
});

it('matches a store-scope row and propagates the (stores, id) coordinate', function () {
    $plaintext = 'plaintext-token-3';
    $context   = Mockery::mock(TokenScopeContext::class);
    $context->shouldReceive('setOwner')
        ->once()
        ->with(ScopeInterface::SCOPE_STORES, 2);

    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization('MyParcel ' . $plaintext),
        makeUserContextCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2, 'value' => hash('sha256', $plaintext)],
        ]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        $context
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
});

it('accepts every casing of the MyParcel scheme', function (string $scheme) {
    $plaintext = 'plaintext-case-' . $scheme;

    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization($scheme . ' ' . $plaintext),
        makeUserContextCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
})->with(['MyParcel', 'myparcel', 'MYPARCEL', 'MyPaRcEl']);

it('returns null when the integration record has not been provisioned (id missing)', function () {
    $plaintext = 'plaintext-no-integration';

    $ctx = new ApiAccessTokenUserContext(
        makeRequestWithAuthorization('MyParcel ' . $plaintext),
        makeUserContextCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        makeIntegrationService(null),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('falls back to $_SERVER[REDIRECT_HTTP_AUTHORIZATION] when the framework Request cannot see the header', function () {
    // Reproduces the Apache "internal redirect prefixes env vars with REDIRECT_" quirk
    // that affects Magento installs with DocumentRoot at the project root rather than pub/.
    $plaintext = 'plaintext-token-redirect';

    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getHeader')->with('Authorization')->andReturn(false);

    $previous = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'MyParcel ' . $plaintext;

    try {
        $ctx = new ApiAccessTokenUserContext(
            $request,
            makeUserContextCollectionFactory([
                ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
            ]),
            makeIntegrationService(FAKE_INTEGRATION_ID),
            makeContext()
        );

        expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
        expect($ctx->getUserId())->toBe(FAKE_INTEGRATION_ID);
    } finally {
        if ($previous === null) {
            unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = $previous;
        }
    }
});

it('processes the request only once across repeated getUserId/getUserType calls', function () {
    $plaintext = 'plaintext-cached';
    $request   = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getHeader')
        ->with('Authorization')
        ->once()
        ->andReturn('MyParcel ' . $plaintext);

    $ctx = new ApiAccessTokenUserContext(
        $request,
        makeUserContextCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        makeIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    $ctx->getUserId();
    $ctx->getUserType();
    $ctx->getUserId();

    // Mockery's ->once() expectation on getHeader('Authorization') asserts the singleton parse.
    expect(true)->toBeTrue();
});
