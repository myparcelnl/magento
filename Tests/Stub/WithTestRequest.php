<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Tests\Stub;

use Magento\Framework\App\RequestInterface;

/**
 * Gives a controller under test a request of its own: the test stub of Magento's Action has no
 * $_request, so getRequest() would read nothing.
 */
trait WithTestRequest
{
    /** @var RequestInterface|null */
    protected $_request;

    public function getRequest()
    {
        return $this->_request;
    }
}
