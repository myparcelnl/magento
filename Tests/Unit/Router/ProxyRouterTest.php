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

it('extracts host and path from /myparcel/proxy/<host>/<path>', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/core/shipments/capabilities');

    $result = $r['router']->match($req['request']);

    expect($result)->toBe($r['action']);
    expect($req['params'])->toBe([
        'upstream_host'       => 'core',
        'upstream_acceptance' => false,
        'upstream_path'       => 'shipments/capabilities',
    ]);
});

it('keeps multi-segment paths intact under the host', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/iam/users/123/sessions');

    $result = $r['router']->match($req['request']);

    expect($result)->toBe($r['action']);
    expect($req['params']['upstream_host'])->toBe('iam');
    expect($req['params']['upstream_acceptance'])->toBeFalse();
    expect($req['params']['upstream_path'])->toBe('users/123/sessions');
});

it('treats /acceptance/ between host and path as an environment flag', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/core/acceptance/shipments/capabilities');

    $result = $r['router']->match($req['request']);

    expect($result)->toBe($r['action']);
    expect($req['params'])->toBe([
        'upstream_host'       => 'core',
        'upstream_acceptance' => true,
        'upstream_path'       => 'shipments/capabilities',
    ]);
});

it('honours the acceptance flag on other hosts too', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/iam/acceptance/sessions');

    $result = $r['router']->match($req['request']);

    expect($result)->toBe($r['action']);
    expect($req['params']['upstream_host'])->toBe('iam');
    expect($req['params']['upstream_acceptance'])->toBeTrue();
    expect($req['params']['upstream_path'])->toBe('sessions');
});

it('returns null when the host segment is present without a path', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/core');

    expect($r['router']->match($req['request']))->toBeNull();
    expect($req['params'])->toBe([]);
});

it('returns null when the acceptance segment is present without a path', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/myparcel/proxy/core/acceptance');

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

it('returns null when the proxy marker is not at the start of the path', function () {
    $r   = makeProxyRouter();
    $req = mockRoutedRequest('/cms/page/myparcel/proxy/core/shipments/capabilities');

    expect($r['router']->match($req['request']))->toBeNull();
    expect($req['params'])->toBe([]);
});
