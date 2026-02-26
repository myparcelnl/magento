<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Response;

use Magento\Framework\Webapi\Rest\Response;
use MyParcelNL\Magento\Model\Rest\AbstractEndpoint;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;

class VersionContentType
{
    /**
     * After the full _render() cycle completes, set the appropriate Content-Type:
     * - Error responses get application/problem+json
     * - Success responses get versioned application/json
     *
     * @param  Response $subject
     * @param  mixed    $result
     * @return mixed
     */
    public function afterPrepareResponse(Response $subject, $result)
    {
        $errorHeader = $subject->getHeader(AbstractEndpoint::SIGNAL_ERROR_HEADER);

        if ($errorHeader) {
            $subject->setHeader('Content-Type', ProblemDetails::CONTENT_TYPE, true);
            $subject->clearHeader(AbstractEndpoint::SIGNAL_ERROR_HEADER);
            $subject->clearHeader(AbstractEndpoint::SIGNAL_HEADER);

            return $result;
        }

        $versionHeader = $subject->getHeader(AbstractEndpoint::SIGNAL_HEADER);

        if (!$versionHeader) {
            return $result;
        }

        $version = (int) $versionHeader->getFieldValue();

        $subject->setHeader(
            'Content-Type',
            'application/json; version=' . $version . '; charset=utf-8',
            true
        );
        $subject->clearHeader(AbstractEndpoint::SIGNAL_HEADER);

        return $result;
    }
}
