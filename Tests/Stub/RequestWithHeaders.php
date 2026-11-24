<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Tests\Stub;

/**
 * Test-only contract that declares `getHeaders()`, so a mocked request passes the `Forwarder`'s
 * `method_exists($request, 'getHeaders')` check. A `shouldReceive` stub alone does not satisfy
 * `method_exists()`. Combine this interface with `RequestInterface` in `Mockery::mock(...)`.
 */
interface RequestWithHeaders
{
    /**
     * @return iterable<object>
     */
    public function getHeaders();
}
