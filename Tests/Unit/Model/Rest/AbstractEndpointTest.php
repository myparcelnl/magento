<?php

declare(strict_types=1);

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use MyParcelNL\Magento\Model\Rest\AbstractEndpoint;
use MyParcelNL\Magento\Model\Rest\AbstractVersionedRequest;
use MyParcelNL\Magento\Model\Rest\VersionContext;

/**
 * Concrete AbstractEndpoint for testing — exposes resolveVersion() publicly
 * and lets each test supply its own version handler map.
 */
if (!class_exists(AbstractEndpointTestFixture::class, false)) {
    class AbstractEndpointTestFixture extends AbstractEndpoint
    {
        /** @var AbstractVersionedRequest[] */
        private array $handlers;

        public function __construct(
            Request        $request,
            Response       $response,
            VersionContext $versionContext,
            array          $handlers
        ) {
            parent::__construct($request, $response, $versionContext);
            $this->handlers = $handlers;
        }

        protected function getVersionHandlers(): array
        {
            return $this->handlers;
        }

        public function exposeResolveVersion(): AbstractVersionedRequest
        {
            return $this->resolveVersion();
        }
    }
}

/**
 * @param  array<string, string> $headers  Keys: "Content-Type", "Accept"; missing keys return null.
 * @param  array<int, AbstractVersionedRequest> $handlers
 */
function makeEndpoint(array $headers, array $handlers, ?VersionContext $ctx = null): AbstractEndpointTestFixture
{
    $request = Mockery::mock(Request::class);
    foreach (['Content-Type', 'Accept'] as $name) {
        $request->shouldReceive('getHeader')
            ->with($name)
            ->andReturn($headers[$name] ?? null);
    }

    $response = Mockery::mock(Response::class);

    return new AbstractEndpointTestFixture($request, $response, $ctx ?? new VersionContext(), $handlers);
}

function makeVersionedRequest(): AbstractVersionedRequest
{
    return new class extends AbstractVersionedRequest {};
}

it('resolves the version from Content-Type', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $endpoint = makeEndpoint(
        ['Content-Type' => 'application/json; version=2'],
        [1 => $v1, 2 => $v2]
    );

    expect($endpoint->exposeResolveVersion())->toBe($v2);
});

it('extracts only the major version from version=v3.1.4-beta', function () {
    $v3       = makeVersionedRequest();
    $endpoint = makeEndpoint(
        ['Content-Type' => 'application/json; version=v3.1.4-beta'],
        [3 => $v3]
    );

    expect($endpoint->exposeResolveVersion())->toBe($v3);
});

it('defaults to the lowest supported version when no version header is present', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $endpoint = makeEndpoint([], [1 => $v1, 2 => $v2]);

    expect($endpoint->exposeResolveVersion())->toBe($v1);
});

it('falls back to the Accept header when Content-Type has no version (ADR §4.2)', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $endpoint = makeEndpoint(
        ['Accept' => 'application/json; version=2'],
        [1 => $v1, 2 => $v2]
    );

    expect($endpoint->exposeResolveVersion())->toBe($v2);
});

it('prefers Content-Type over Accept when both carry a version', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $endpoint = makeEndpoint(
        [
            'Content-Type' => 'application/json; version=2',
            'Accept'       => 'application/json; version=1; version=2',
        ],
        [1 => $v1, 2 => $v2]
    );

    expect($endpoint->exposeResolveVersion())->toBe($v2);
});

it('throws 406 when the Content-Type version is unsupported (ADR §5.1)', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $endpoint = makeEndpoint(
        ['Content-Type' => 'application/json; version=3'],
        [1 => $v1, 2 => $v2]
    );

    try {
        $endpoint->exposeResolveVersion();
        throw new RuntimeException('Expected WebapiException was not thrown');
    } catch (WebapiException $e) {
        expect($e->getHttpCode())->toBe(WebapiException::HTTP_NOT_ACCEPTABLE);
    }
});

it('throws 406 when the Accept version is unsupported and Content-Type is absent (ADR §5.1)', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $endpoint = makeEndpoint(
        ['Accept' => 'application/json; version=3'],
        [1 => $v1, 2 => $v2]
    );

    try {
        $endpoint->exposeResolveVersion();
        throw new RuntimeException('Expected WebapiException was not thrown');
    } catch (WebapiException $e) {
        expect($e->getHttpCode())->toBe(WebapiException::HTTP_NOT_ACCEPTABLE);
    }
});

it('uses min(supported) as default when v1 is not in the supported set', function () {
    // PDK hardcodes v1 as default and 406's here; Magento uses min(supported) = v2,
    // which is supported — documents the intentional divergence from PDK.
    $v2       = makeVersionedRequest();
    $v3       = makeVersionedRequest();
    $endpoint = makeEndpoint([], [2 => $v2, 3 => $v3]);

    expect($endpoint->exposeResolveVersion())->toBe($v2);
});

it('throws 409 when Content-Type version is not listed in Accept versions (ADR §5.2)', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $endpoint = makeEndpoint(
        [
            'Content-Type' => 'application/json; version=1',
            'Accept'       => 'application/json; version=2',
        ],
        [1 => $v1, 2 => $v2]
    );

    try {
        $endpoint->exposeResolveVersion();
        throw new RuntimeException('Expected WebapiException was not thrown');
    } catch (WebapiException $e) {
        expect($e->getHttpCode())->toBe(409);
    }
});

it('publishes the supported versions to VersionContext', function () {
    $v1       = makeVersionedRequest();
    $v2       = makeVersionedRequest();
    $ctx      = new VersionContext();
    $endpoint = makeEndpoint([], [1 => $v1, 2 => $v2], $ctx);

    $endpoint->exposeResolveVersion();

    expect($ctx->getSupportedVersions())->toBe([1, 2]);
});
