<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Response;

use Magento\Framework\Webapi\Rest\Response;
use MyParcelNL\Magento\Model\Rest\VersionContext;

class VersionContentType
{
    private VersionContext $versionContext;

    public function __construct(VersionContext $versionContext)
    {
        $this->versionContext = $versionContext;
    }

    /**
     * After the full _render() cycle completes, set the appropriate Content-Type:
     * - Error responses get application/problem+json
     * - Success responses get versioned application/json + Accept header with supported versions
     *
     * @param  Response $subject
     * @param  mixed    $result
     * @return mixed
     */
    public function afterPrepareResponse(Response $subject, $result)
    {
        if (!$this->versionContext->isActive()) {
            return $result;
        }

        if ($this->versionContext->isError()) {
            $subject->setHeader('Content-Type', 'application/problem+json; charset=utf-8', true);

            return $result;
        }

        $version = $this->versionContext->getNegotiatedVersion();

        $subject->setHeader(
            'Content-Type',
            'application/json; version=' . $version . '; charset=utf-8',
            true
        );

        $acceptParts = [];
        foreach ($this->versionContext->getSupportedVersions() as $supportedVersion) {
            $acceptParts[] = 'application/json; version=' . $supportedVersion;
        }

        if (!empty($acceptParts)) {
            $subject->setHeader('Accept', implode(', ', $acceptParts), true);
        }

        return $result;
    }
}
