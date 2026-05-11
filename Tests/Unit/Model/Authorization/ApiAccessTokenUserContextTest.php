<?php

declare(strict_types=1);

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Authorization\ApiAccessTokenUserContext;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

const FAKE_INTEGRATION_ID = 7;

function makeContext(): TokenScopeContext
{
    return Mockery::mock(TokenScopeContext::class)->shouldIgnoreMissing();
}

it('returns null userId/userType when no Authorization header is present', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization(null),
        mockCollectionFactory([]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null userId/userType when scheme is Bearer (so the native chain handles it)', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('Bearer some-magento-admin-token'),
        mockCollectionFactory([]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null when MyParcel scheme is present but the token is empty', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel '),
        mockCollectionFactory([]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null when no row hashes the presented token', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel deadbeef'),
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', 'a-different-token')],
        ]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
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
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
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
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', 'unrelated')],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1, 'value' => hash('sha256', $plaintext)],
        ]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
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
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2, 'value' => hash('sha256', $plaintext)],
        ]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        $context
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
});

it('accepts every casing of the MyParcel scheme', function (string $scheme) {
    $plaintext = 'plaintext-case-' . $scheme;

    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization($scheme . ' ' . $plaintext),
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
})->with(['MyParcel', 'myparcel', 'MYPARCEL', 'MyPaRcEl']);

it('returns null when the integration record has not been provisioned (id missing)', function () {
    $plaintext = 'plaintext-no-integration';

    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        mockIntegrationService(null),
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
            mockCollectionFactory([
                ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
            ]),
            mockIntegrationService(FAKE_INTEGRATION_ID),
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
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)],
        ]),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeContext()
    );

    $ctx->getUserId();
    $ctx->getUserType();
    $ctx->getUserId();

    // Mockery's ->once() expectation on getHeader('Authorization') asserts the singleton parse.
    expect(true)->toBeTrue();
});

/**
 * Coverage regression: INTEGRATION_NAME is the lookup key UserContext uses against
 * IntegrationServiceInterface::findByName(). A rename of the <integration name="…"/>
 * in etc/integration.xml without updating the constant silently breaks every
 * token-authenticated request — findByName returns an empty Integration, getUserId
 * returns null, the caller gets a generic 401. Static guard catches the drift.
 */
it('INTEGRATION_NAME matches the <integration name="..."> declared in etc/integration.xml', function () {
    $moduleRoot     = dirname(__DIR__, 4);
    $integrationXml = simplexml_load_file($moduleRoot . '/etc/integration.xml');
    expect($integrationXml)->not->toBeFalse();

    expect((string) $integrationXml->integration['name'])->toBe(ApiAccessTokenUserContext::INTEGRATION_NAME);
});
