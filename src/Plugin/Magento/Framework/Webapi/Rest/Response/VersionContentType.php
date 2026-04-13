<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Response;

use Magento\Framework\Webapi\Rest\Response;
use MyParcelNL\Magento\Model\Rest\VersionContext;

class VersionContentType
{
    private const SUCCESS_MEDIA_TYPE = 'application/json';
    private const ERROR_MEDIA_TYPE   = 'application/problem+json';

    private VersionContext $versionContext;

    public function __construct(VersionContext $versionContext)
    {
        $this->versionContext = $versionContext;
    }

    /**
     * After the full _render() cycle completes, set response headers per ADR 0011 Section 3:
     *  - Content-Type carries the negotiated version in a `version` parameter.
     *  - Accept lists all supported versions as repeated `version` parameters on a single media type.
     *
     * These requirements apply to all responses (success and error) from a versioned endpoint.
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

        $supportedVersions = $this->versionContext->getSupportedVersions();
        $mediaType         = $this->versionContext->isError()
            ? self::ERROR_MEDIA_TYPE
            : self::SUCCESS_MEDIA_TYPE;

        // If a pre-negotiation error (406/409) is raised in resolveVersion(), no version has been
        // negotiated; fall back to the lowest supported major version per ADR 4.1.
        $negotiatedVersion = $this->versionContext->getNegotiatedVersion()
            ?? (empty($supportedVersions) ? null : min($supportedVersions));

        if ($negotiatedVersion !== null) {
            $subject->setHeader(
                'Content-Type',
                $mediaType . '; version=' . $negotiatedVersion . '; charset=utf-8',
                true
            );
        }

        if (!empty($supportedVersions)) {
            $subject->setHeader(
                'Accept',
                $this->buildVersionList($mediaType, $supportedVersions),
                true
            );
        }

        return $result;
    }

    /**
     * Build an ADR 0011 Section 3 `Accept` header value: a single media type with one `version`
     * parameter per supported version, e.g. `application/json; version=1; version=2`.
     *
     * @param string $mediaType
     * @param int[]  $versions
     */
    private function buildVersionList(string $mediaType, array $versions): string
    {
        $parts = [$mediaType];
        foreach ($versions as $version) {
            $parts[] = 'version=' . $version;
        }

        return implode('; ', $parts);
    }
}
