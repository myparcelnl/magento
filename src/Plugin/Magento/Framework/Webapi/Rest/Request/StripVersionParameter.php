<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Request;

use Magento\Framework\Webapi\Rest\Request;
use MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\MyParcelEndpointAware;

/**
 * Strip `version=` from the Content-Type header before Magento validates it.
 *
 * Magento's Request::getContentType() regex only allows `charset=` as a
 * parameter. ADR-0011 requires `version=` in Content-Type for versioned
 * endpoints. This plugin removes the version parameter so Magento's
 * validation passes, while the raw header (used by AbstractEndpoint::negotiate())
 * remains intact.
 */
class StripVersionParameter
{
    use MyParcelEndpointAware;

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function aroundGetContentType(Request $subject, callable $proceed): string
    {
        if (! $this->isMyParcelEndpoint()) {
            return $proceed();
        }

        $original = $subject->getHeader('Content-Type');

        if (! $original || ! is_string($original)) {
            return $proceed();
        }

        // Strip version parameter(s), preserving charset and other params
        $stripped = preg_replace('/;?\s*version=v?\d+(\.\d+)*/i', '', $original);
        $stripped = rtrim($stripped, '; ');

        $headers = $subject->getHeaders();
        $this->replaceContentType($headers, $stripped);

        try {
            return $proceed();
        } finally {
            $this->replaceContentType($headers, $original);
        }
    }

    private function replaceContentType($headers, string $value): void
    {
        $existing = $headers->get('Content-Type');

        if ($existing) {
            $headers->removeHeader($existing);
        }

        $headers->addHeaderLine('Content-Type', $value);
    }
}
