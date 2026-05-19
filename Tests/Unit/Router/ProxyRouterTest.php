<?php

declare(strict_types=1);

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use MyParcelNL\Magento\Router\ProxyRouter;

function makeProxyRouter(): array
{
    $action  = Mockery::mock(ActionInterface::class);
    $factory = Mockery::mock(ActionFactory::class);
    $factory->shouldReceive('create')->with('FakeAction')->andReturn($action);

    return [
        'router' => new ProxyRouter($factory, 'FakeAction'),
        'action' => $action,
    ];
}

function mockRoutedRequest(string $pathInfo): array
{
    $params  = [];
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getPathInfo')->andReturn($pathInfo);
    $request->shouldReceive('setParam')->andReturnUsing(
        function (string $name, $value) use (&$params, $request) {
            $params[$name] = $value;
            return $request;
        }
    );
    $request->shouldReceive('setModuleName')->andReturnSelf();
    $request->shouldReceive('setControllerName')->andReturnSelf();
    $request->shouldReceive('setActionName')->andReturnSelf();

    return ['request' => $request, 'params' => &$params];
}

it('extracts upstream key and path from /myparcel/proxy/<key>/<path>', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/core/shipments/capabilities');

    $result = $r['router']->match($req['request']);

    expect($result)->toBe($r['action']);
    expect($req['params'])->toBe([
        'upstream_key'  => 'core',
        'upstream_path' => 'shipments/capabilities',
    ]);
});

it('keeps multi-segment paths intact under the key', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/order/orders/123/items');

    $result = $r['router']->match($req['request']);

    expect($result)->toBe($r['action']);
    expect($req['params']['upstream_key'])->toBe('order');
    expect($req['params']['upstream_path'])->toBe('orders/123/items');
});

it('returns null when the upstream key is present without a path', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/core');

    expect($r['router']->match($req['request']))->toBeNull();
    expect($req['params'])->toBe([]);
});

it('returns null when no segment follows the proxy marker', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/');

    expect($r['router']->match($req['request']))->toBeNull();
});

it('returns null when the path does not contain the proxy marker', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/some/other/path');

    expect($r['router']->match($req['request']))->toBeNull();
});
