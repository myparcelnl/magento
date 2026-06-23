<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\InputException;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Controller\Adminhtml\ApiAccessToken\Revoke;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

/**
 * Skip parent::__construct(Action) and inject getRequest() override to avoid mocking
 * the Backend Action context chain.
 */
final class FakeRevoke extends Revoke
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
 * @return array{controller: FakeRevoke, captured: array}
 */
function makeRevokeBag(array $opts = []): array
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
        'controller' => new FakeRevoke($request, $factory, $service),
        'captured'   => &$captured,
    ];
}

it('returns success=true when revoke succeeds', function () {
    $service = Mockery::mock(TokenService::class);
    $service->shouldReceive('revokeForScope')
        ->once()
        ->with(ScopeInterface::SCOPE_STORES, 2)
        ->andReturnNull();

    $bag = makeRevokeBag([
        'params'  => ['scope' => ScopeInterface::SCOPE_STORES, 'scopeId' => 2],
        'service' => $service,
    ]);

    $bag['controller']->execute();

    expect($bag['captured']['status'])->toBeNull();
    expect($bag['captured']['data'])->toBe(['success' => true]);
});

it('maps InputException to HTTP 400 with the exception message', function () {
    $service = Mockery::mock(TokenService::class);
    $service->shouldReceive('revokeForScope')
        ->andThrow(new InputException(__('Unsupported scope "group".')));

    $bag = makeRevokeBag([
        'params'  => ['scope' => 'group', 'scopeId' => 1],
        'service' => $service,
    ]);

    $bag['controller']->execute();

    expect($bag['captured']['status'])->toBe(400);
    expect($bag['captured']['data'])->toBe(['success' => false, 'message' => 'Unsupported scope "group".']);
});
