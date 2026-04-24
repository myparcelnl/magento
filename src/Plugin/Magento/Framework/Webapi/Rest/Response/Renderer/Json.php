<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Response\Renderer;

use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response\Renderer\Json as JsonRenderer;
use MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\MyParcelEndpointAware;

class Json
{
    use MyParcelEndpointAware;

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Prevent double JSON encoding for MyParcel endpoints that return pre-encoded JSON strings.
     *
     * @param JsonRenderer $subject
     * @param string       $result
     * @param mixed        $data
     *
     * @return string
     */
    public function afterRender(JsonRenderer $subject, string $result, $data): string
    {
        if (is_string($data) && $this->isMyParcelEndpoint()) {
            return $data;
        }

        return $result;
    }

}
