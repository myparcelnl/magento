<?php

declare(strict_types=1);

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Authorization\ApiAccessTokenUserContext;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

const FAKE_INTEGRATION_ID = 7;

/**
 * TokenScopeContext mock for tests where UserContext should NOT reach findByHash
 * (missing header, Bearer, empty token). Any unexpected interaction surfaces as
 * an assertion failure rather than silently passing.
 */
function makeScopeContextNeverCalled(): TokenScopeContext
{
    $ctx = Mockery::mock(TokenScopeContext::class);
    $ctx->shouldNotReceive('findByHash');
    $ctx->shouldNotReceive('setOwner');
    return $ctx;
}

/**
 * TokenScopeContext mock for tests where UserContext calls findByHash but it returns null
 * (token presented but no matching row).
 */
function makeScopeContextNoMatch(): TokenScopeContext
{
    $ctx = Mockery::mock(TokenScopeContext::class);
    $ctx->shouldReceive('findByHash')->andReturnNull();
    $ctx->shouldNotReceive('setOwner');
    return $ctx;
}

/**
 * TokenScopeContext mock for tests where UserContext should match and propagate the owner.
 *
 * @param array{scope: string, scopeId: int} $coord
 */
function makeScopeContextMatching(string $plaintext, array $coord): TokenScopeContext
{
    $ctx = Mockery::mock(TokenScopeContext::class);
    $ctx->shouldReceive('findByHash')->with($plaintext)->andReturn($coord);
    $ctx->shouldReceive('setOwner')->once()->with($coord['scope'], $coord['scopeId']);
    return $ctx;
}

it('returns null userId/userType when no Authorization header is present', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization(null),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextNeverCalled()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null userId/userType when scheme is Bearer (so the native chain handles it)', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('Bearer some-magento-admin-token'),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextNeverCalled()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null when MyParcel scheme is present but the token is empty', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel '),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextNeverCalled()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('returns null when TokenScopeContext::findByHash finds no matching row', function () {
    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel deadbeef'),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextNoMatch()
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('propagates the matched default-scope coordinate and returns USER_TYPE_INTEGRATION', function () {
    $plaintext = 'plaintext-token-1';
    $coord     = ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0];

    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextMatching($plaintext, $coord)
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($ctx->getUserId())->toBe(FAKE_INTEGRATION_ID);
});

it('propagates the matched website-scope coordinate', function () {
    $plaintext = 'plaintext-token-2';
    $coord     = ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 1];

    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextMatching($plaintext, $coord)
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
});

it('propagates the matched store-scope coordinate', function () {
    $plaintext = 'plaintext-token-3';
    $coord     = ['scope' => ScopeInterface::SCOPE_STORES, 'scopeId' => 2];

    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextMatching($plaintext, $coord)
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
});

it('accepts every casing of the MyParcel scheme', function (string $scheme) {
    $plaintext = 'plaintext-case-' . $scheme;
    $coord     = ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0];

    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization($scheme . ' ' . $plaintext),
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextMatching($plaintext, $coord)
    );

    expect($ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
})->with(['MyParcel', 'myparcel', 'MYPARCEL', 'MyPaRcEl']);

it('returns null when the integration record has not been provisioned (id missing)', function () {
    $plaintext = 'plaintext-no-integration';
    $coord     = ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0];

    // setOwner is not expected when integration resolution fails — UserContext bails before that.
    $scopeContext = Mockery::mock(TokenScopeContext::class);
    $scopeContext->shouldReceive('findByHash')->with($plaintext)->andReturn($coord);
    $scopeContext->shouldNotReceive('setOwner');

    $ctx = new ApiAccessTokenUserContext(
        mockRequestWithAuthorization('MyParcel ' . $plaintext),
        mockIntegrationService(null),
        $scopeContext
    );

    expect($ctx->getUserId())->toBeNull();
    expect($ctx->getUserType())->toBeNull();
});

it('falls back to $_SERVER[REDIRECT_HTTP_AUTHORIZATION] when the framework Request cannot see the header', function () {
    // Reproduces the Apache "internal redirect prefixes env vars with REDIRECT_" quirk
    // that affects Magento installs with DocumentRoot at the project root rather than pub/.
    $plaintext = 'plaintext-token-redirect';
    $coord     = ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0];

    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getHeader')->with('Authorization')->andReturn(false);

    $previous = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'MyParcel ' . $plaintext;

    try {
        $ctx = new ApiAccessTokenUserContext(
            $request,
            mockIntegrationService(FAKE_INTEGRATION_ID),
            makeScopeContextMatching($plaintext, $coord)
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
    $coord     = ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0];

    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getHeader')
        ->with('Authorization')
        ->once()
        ->andReturn('MyParcel ' . $plaintext);

    $ctx = new ApiAccessTokenUserContext(
        $request,
        mockIntegrationService(FAKE_INTEGRATION_ID),
        makeScopeContextMatching($plaintext, $coord)
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
