<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Tests\Stub;

/**
 * Test-only contract used to mock a Magento request that exposes
 * `getHeaders()`. The production concrete request
 * (`Magento\Framework\HTTP\PhpEnvironment\Request`) provides this
 * method, but `Magento\Framework\App\RequestInterface` does not declare
 * it — and Mockery's `shouldReceive` is routed through `__call`, which
 * does not satisfy `method_exists()`. The `Forwarder` gates its header
 * collection on `method_exists($request, 'getHeaders')`, so tests that
 * want to exercise the populated-headers branch need a mock that
 * actually declares the method. Combine this interface with
 * `RequestInterface` in a `Mockery::mock(...)` call to do that.
 */
interface RequestWithHeaders
{
    /**
     * @return iterable<object>
     */
    public function getHeaders();
}
