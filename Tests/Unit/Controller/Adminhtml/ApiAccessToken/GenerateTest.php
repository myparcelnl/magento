<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Controller\Adminhtml\ApiAccessToken\Generate;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

/**
 * Skip parent::__construct(Action) and inject getRequest() override to avoid mocking
 * the Backend Action context chain.
 */
final class FakeGenerate extends Generate
{
    /** @var RequestInterface|null Shadow the parent declaration under the test stub autoloader. */
    protected $_request;

    public function __construct(
        RequestInterface $request,
        JsonFactory      $jsonFactory,
        TokenService     $tokenService
    ) {
        $this->_request     = $request;
        $this->jsonFactory  = $jsonFactory;
        $this->tokenService = $tokenService;
    }

    public function getRequest()
    {
        return $this->_request;
    }
}

/**
 * @param array{params?: array, service?: TokenService} $opts
 * @return array{controller: FakeGenerate, captured: array}
 */
function makeGenerateBag(array $opts = []): array
{
    $captured = ['status' => null, 'data' => null];

    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParams')->andReturn($opts['params'] ?? []);

    $json = Mockery::mock(Json::class);
    $json->shouldReceive('setHttpResponseCode')->andReturnUsing(function ($code) use (&$captured, $json) {
        $captured['status'] = (int) $code;
        return $json;
    });
    $json->shouldReceive('setData')->andReturnUsing(function ($data) use (&$captured, $json) {
        $captured['data'] = $data;
        return $json;
    });

    $factory = Mockery::mock(JsonFactory::class);
    $factory->shouldReceive('create')->andReturn($json);

    $service = $opts['service'] ?? Mockery::mock(TokenService::class);

    return [
        'controller' => new FakeGenerate($request, $factory, $service),
        'captured'   => &$captured,
    ];
}

it('returns success=true with the token when generate succeeds', function () {
    $service = Mockery::mock(TokenService::class);
    $service->shouldReceive('generateForScope')
        ->once()
        ->with(ScopeInterface::SCOPE_WEBSITES, 7)
        ->andReturn('deadbeef');

    $bag = makeGenerateBag([
        'params'  => ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 7],
        'service' => $service,
    ]);

    $bag['controller']->execute();

    expect($bag['captured']['status'])->toBeNull();
    expect($bag['captured']['data'])->toBe(['success' => true, 'token' => 'deadbeef']);
});

it('maps AlreadyExistsException to HTTP 409 with the exception message', function () {
    $service = Mockery::mock(TokenService::class);
    $service->shouldReceive('generateForScope')
        ->andThrow(new AlreadyExistsException(__('hash collision')));

    $bag = makeGenerateBag([
        'params'  => ['scope' => ScopeInterface::SCOPE_STORES, 'scopeId' => 2],
        'service' => $service,
    ]);

    $bag['controller']->execute();

    expect($bag['captured']['status'])->toBe(409);
    expect($bag['captured']['data'])->toBe(['success' => false, 'message' => 'hash collision']);
});

it('maps InputException to HTTP 400 with the exception message', function () {
    $service = Mockery::mock(TokenService::class);
    $service->shouldReceive('generateForScope')
        ->andThrow(new InputException(__('Unsupported scope "group".')));

    $bag = makeGenerateBag([
        'params'  => ['scope' => 'group', 'scopeId' => 1],
        'service' => $service,
    ]);

    $bag['controller']->execute();

    expect($bag['captured']['status'])->toBe(400);
    expect($bag['captured']['data'])->toBe(['success' => false, 'message' => 'Unsupported scope "group".']);
});

it('defaults scope to default and scopeId to 0 when params are absent', function () {
    $service = Mockery::mock(TokenService::class);
    $service->shouldReceive('generateForScope')
        ->once()
        ->with(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0)
        ->andReturn('plaintext');

    $bag = makeGenerateBag(['service' => $service]);

    $bag['controller']->execute();

    expect($bag['captured']['data'])->toBe(['success' => true, 'token' => 'plaintext']);
});
