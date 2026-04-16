<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest;

/**
 * Shared check for plugins that only act on MyParcel REST endpoints.
 *
 * Requires the using class to declare: private Request $request;
 */
trait MyParcelEndpointAware
{
    private function isMyParcelEndpoint(): bool
    {
        return str_contains($this->request->getPathInfo() ?? '', 'myparcel/');
    }
}
