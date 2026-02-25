<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Response;

use Magento\Framework\Webapi\Rest\Response;

class VersionContentType
{
    private const SIGNAL_HEADER = 'X-MyParcel-Api-Version';

    /**
     * After the full _render() cycle completes, replace Content-Type with the versioned variant
     * and remove the internal signal header.
     *
     * @param  Response $subject
     * @param  mixed    $result
     * @return mixed
     */
    public function afterPrepareResponse(Response $subject, $result)
    {
        $versionHeader = $subject->getHeader(self::SIGNAL_HEADER);

        if (!$versionHeader) {
            return $result;
        }

        $version = $versionHeader->getFieldValue();

        $subject->setHeader(
            'Content-Type',
            'application/json; version=' . $version . '; charset=utf-8',
            true
        );
        $subject->clearHeader(self::SIGNAL_HEADER);

        return $result;
    }
}
