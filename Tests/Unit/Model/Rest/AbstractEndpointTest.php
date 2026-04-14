<?php

declare(strict_types=1);

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use MyParcelNL\Magento\Model\Rest\AbstractEndpoint;
use MyParcelNL\Magento\Model\Rest\AbstractVersionedResource;
use MyParcelNL\Magento\Model\Rest\VersionContext;

/**
 * Concrete AbstractEndpoint for testing — exposes negotiate() and createResource()
 * publicly and lets each test supply its own handler maps.
 */
if (!class_exists(AbstractEndpointTestFixture::class, false)) {
    class AbstractEndpointTestFixture extends AbstractEndpoint
    {
        /** @var object[] */
        private array $requestHandlers;

        /** @var array<int, class-string<AbstractVersionedResource>> */
        private array $resourceHandlers;

        public function __construct(
            Request        $request,
            Response       $response,
            VersionContext $versionContext,
            array          $requestHandlers,
            array          $resourceHandlers
        ) {
            parent::__construct($request, $response, $versionContext);
            $this->requestHandlers  = $requestHandlers;
            $this->resourceHandlers = $resourceHandlers;
        }

        protected function getRequestHandlers(): array
        {
            return $this->requestHandlers;
        }

        protected function getResourceHandlers(): array
        {
            return $this->resourceHandlers;
        }

        public function exposeNegotiate(): object
        {
            return $this->negotiate();
        }

        public function exposeCreateResource(array $data): AbstractVersionedResource
        {
            return $this->createResource($data);
        }
    }
}

if (!class_exists(StubResourceV1::class, false)) {
    class StubResourceV1 extends AbstractVersionedResource
    {
        private array $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public static function getVersion(): int
        {
            return 1;
        }

        public function format(): array
        {
            return $this->data;
        }
    }
}

if (!class_exists(StubResourceV2::class, false)) {
    class StubResourceV2 extends AbstractVersionedResource
    {
        private array $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public static function getVersion(): int
        {
            return 2;
        }

        public function format(): array
        {
            return $this->data;
        }
    }
}

/**
 * @param  array<string, string>  $headers           Keys: "Content-Type", "Accept"
 * @param  array<int, object>     $requestHandlers
 * @param  array<int, class-string<AbstractVersionedResource>>|null $resourceHandlers  Defaults to matching request versions with StubResourceV1/V2
 */
function makeEndpoint(
    array $headers,
    array $requestHandlers,
    ?VersionContext $ctx = null,
    ?array $resourceHandlers = null
): AbstractEndpointTestFixture {
    $request = Mockery::mock(Request::class);
    foreach (['Content-Type', 'Accept'] as $name) {
        $request->shouldReceive('getHeader')
            ->with($name)
            ->andReturn($headers[$name] ?? null);
    }

    $response = Mockery::mock(Response::class);

    if ($resourceHandlers === null) {
        $resourceHandlers = [];
        foreach (array_keys($requestHandlers) as $v) {
            $resourceHandlers[$v] = $v === 2 ? StubResourceV2::class : StubResourceV1::class;
        }
    }

    return new AbstractEndpointTestFixture(
        $request,
        $response,
        $ctx ?? new VersionContext(),
        $requestHandlers,
        $resourceHandlers
    );
}

function makeRequestHandler(): object
{
    return new \stdClass();
}

// ---------------------------------------------------------------------------
// Request version resolution
// ---------------------------------------------------------------------------

it('resolves the request version from Content-Type', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $endpoint = makeEndpoint(
        ['Content-Type' => 'application/json; version=2'],
        [1 => $v1, 2 => $v2]
    );

    expect($endpoint->exposeNegotiate())->toBe($v2);
});

it('extracts only the major version from version=v3.1.4-beta', function () {
    $v3       = makeRequestHandler();
    $endpoint = makeEndpoint(
        ['Content-Type' => 'application/json; version=v3.1.4-beta'],
        [3 => $v3],
        null,
        [3 => StubResourceV1::class]
    );

    expect($endpoint->exposeNegotiate())->toBe($v3);
});

it('defaults to the lowest supported request version when no headers are present (ADR §4.1)', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $endpoint = makeEndpoint([], [1 => $v1, 2 => $v2]);

    expect($endpoint->exposeNegotiate())->toBe($v1);
});

it('uses min(requestSupported) as default when v1 is not in the request handler set', function () {
    // PDK hardcodes v1 as default and 406's here; Magento uses min(supported) = v2,
    // which is supported — documents the intentional divergence from PDK.
    $v2       = makeRequestHandler();
    $v3       = makeRequestHandler();
    $endpoint = makeEndpoint(
        [],
        [2 => $v2, 3 => $v3],
        null,
        [2 => StubResourceV1::class, 3 => StubResourceV2::class]
    );

    expect($endpoint->exposeNegotiate())->toBe($v2);
});

it('throws 406 when the Content-Type request version is unsupported (ADR §5.1)', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $endpoint = makeEndpoint(
        ['Content-Type' => 'application/json; version=3'],
        [1 => $v1, 2 => $v2]
    );

    try {
        $endpoint->exposeNegotiate();
        throw new RuntimeException('Expected WebapiException was not thrown');
    } catch (WebapiException $e) {
        expect($e->getHttpCode())->toBe(WebapiException::HTTP_NOT_ACCEPTABLE);
        expect($e->getMessage())->toContain('Request version');
    }
});

// ---------------------------------------------------------------------------
// Response version resolution
// ---------------------------------------------------------------------------

it('resolves the response version from Accept header', function () {
    $v1       = makeRequestHandler();
    $ctx      = new VersionContext();
    $endpoint = makeEndpoint(
        ['Accept' => 'application/json; version=2'],
        [1 => $v1],
        $ctx,
        [1 => StubResourceV1::class, 2 => StubResourceV2::class]
    );

    $endpoint->exposeNegotiate();

    expect($ctx->getNegotiatedRequestVersion())->toBe(1);
    expect($ctx->getNegotiatedResponseVersion())->toBe(2);
});

it('defaults response version to Content-Type version when Accept is absent (ADR §4.2)', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $ctx      = new VersionContext();
    $endpoint = makeEndpoint(
        ['Content-Type' => 'application/json; version=2'],
        [1 => $v1, 2 => $v2],
        $ctx
    );

    $endpoint->exposeNegotiate();

    expect($ctx->getNegotiatedRequestVersion())->toBe(2);
    expect($ctx->getNegotiatedResponseVersion())->toBe(2);
});

it('defaults both versions to min(requestSupported) when no headers are present (ADR §4.1 + §4.2)', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $ctx      = new VersionContext();
    $endpoint = makeEndpoint([], [1 => $v1, 2 => $v2], $ctx);

    $endpoint->exposeNegotiate();

    expect($ctx->getNegotiatedRequestVersion())->toBe(1);
    expect($ctx->getNegotiatedResponseVersion())->toBe(1);
});

it('throws 406 when the Accept response version is unsupported (ADR §5.1)', function () {
    $v1       = makeRequestHandler();
    $endpoint = makeEndpoint(
        ['Accept' => 'application/json; version=3'],
        [1 => $v1],
        null,
        [1 => StubResourceV1::class]
    );

    try {
        $endpoint->exposeNegotiate();
        throw new RuntimeException('Expected WebapiException was not thrown');
    } catch (WebapiException $e) {
        expect($e->getHttpCode())->toBe(WebapiException::HTTP_NOT_ACCEPTABLE);
        expect($e->getMessage())->toContain('Response version');
    }
});

// ---------------------------------------------------------------------------
// Independent request/response versioning
// ---------------------------------------------------------------------------

it('negotiates request and response versions independently', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $ctx      = new VersionContext();
    $endpoint = makeEndpoint(
        [
            'Content-Type' => 'application/json; version=1',
            'Accept'       => 'application/json; version=1; version=2',
        ],
        [1 => $v1, 2 => $v2],
        $ctx
    );

    expect($endpoint->exposeNegotiate())->toBe($v1);
    expect($ctx->getNegotiatedRequestVersion())->toBe(1);
    expect($ctx->getNegotiatedResponseVersion())->toBe(1);
});

it('Content-Type drives request while Accept drives response', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $ctx      = new VersionContext();
    // Content-Type=v1, Accept lists v1 and v2 — first Accept version is v1
    $endpoint = makeEndpoint(
        [
            'Content-Type' => 'application/json; version=1',
            'Accept'       => 'application/json; version=2; version=1',
        ],
        [1 => $v1, 2 => $v2],
        $ctx
    );

    expect($endpoint->exposeNegotiate())->toBe($v1);
    expect($ctx->getNegotiatedRequestVersion())->toBe(1);
    expect($ctx->getNegotiatedResponseVersion())->toBe(2);
});

// ---------------------------------------------------------------------------
// 409 Conflict (ADR §5.2)
// ---------------------------------------------------------------------------

it('throws 409 when Content-Type version is not listed in Accept versions (ADR §5.2)', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $endpoint = makeEndpoint(
        [
            'Content-Type' => 'application/json; version=1',
            'Accept'       => 'application/json; version=2',
        ],
        [1 => $v1, 2 => $v2]
    );

    try {
        $endpoint->exposeNegotiate();
        throw new RuntimeException('Expected WebapiException was not thrown');
    } catch (WebapiException $e) {
        expect($e->getHttpCode())->toBe(409);
    }
});

// ---------------------------------------------------------------------------
// VersionContext publication
// ---------------------------------------------------------------------------

it('publishes supported request and response versions to VersionContext', function () {
    $v1       = makeRequestHandler();
    $v2       = makeRequestHandler();
    $ctx      = new VersionContext();
    $endpoint = makeEndpoint(
        [],
        [1 => $v1, 2 => $v2],
        $ctx,
        [1 => StubResourceV1::class, 2 => StubResourceV2::class]
    );

    $endpoint->exposeNegotiate();

    expect($ctx->getSupportedRequestVersions())->toBe([1, 2]);
    expect($ctx->getSupportedResponseVersions())->toBe([1, 2]);
});

// ---------------------------------------------------------------------------
// createResource
// ---------------------------------------------------------------------------

it('createResource instantiates the correct resource class for the negotiated response version', function () {
    $v1       = makeRequestHandler();
    $ctx      = new VersionContext();
    $endpoint = makeEndpoint(
        ['Accept' => 'application/json; version=2'],
        [1 => $v1],
        $ctx,
        [1 => StubResourceV1::class, 2 => StubResourceV2::class]
    );

    $endpoint->exposeNegotiate();
    $resource = $endpoint->exposeCreateResource(['foo' => 'bar']);

    expect($resource)->toBeInstanceOf(StubResourceV2::class);
    expect($resource::getVersion())->toBe(2);
    expect($resource->format())->toBe(['foo' => 'bar']);
});
