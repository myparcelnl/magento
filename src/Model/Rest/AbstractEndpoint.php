<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;

abstract class AbstractEndpoint
{
    private const VERSION_PATTERN = '/version=v?(\d+)/i';

    private Request $request;
    private Response $response;
    private VersionContext $versionContext;

    public function __construct(Request $request, Response $response, VersionContext $versionContext)
    {
        $this->request        = $request;
        $this->response       = $response;
        $this->versionContext = $versionContext;
    }

    /**
     * Subclass returns [1 => $v1Handler, 2 => $v2Handler, ...].
     *
     * @return AbstractVersionedRequest[]
     */
    abstract protected function getVersionHandlers(): array;

    /**
     * Negotiate the request version per ADR 0011.
     *
     * - Section 4.1: when no `version` parameter is provided in the Content-Type request header,
     *   the lowest supported major version is assumed.
     * - Section 4.2: when Content-Type carries a version, it is the negotiated version.
     * - Section 5.2: incompatible Content-Type / Accept versions trigger 409.
     * - Section 5.1: unsupported versions trigger 406.
     *
     * @throws WebapiException 406 if version is unsupported, 409 if Content-Type and Accept conflict
     */
    protected function resolveVersion(): AbstractVersionedRequest
    {
        $handlers          = $this->getVersionHandlers();
        $supportedVersions = array_keys($handlers);

        $this->versionContext->setSupportedVersions($supportedVersions);

        $contentTypeVersion = $this->extractVersionFromHeader('Content-Type');
        $acceptVersions     = $this->extractAllVersionsFromHeader('Accept');

        if ($contentTypeVersion !== null
            && !empty($acceptVersions)
            && !in_array($contentTypeVersion, $acceptVersions, true)
        ) {
            throw new WebapiException(
                __('Content-Type version %1 is not listed in Accept versions: %2.',
                    $contentTypeVersion,
                    implode(', ', $acceptVersions)
                ),
                0,
                409
            );
        }

        $version = $contentTypeVersion ?? min($supportedVersions);

        if (!isset($handlers[$version])) {
            throw new WebapiException(
                __('API version %1 is not supported. Supported versions: %2.',
                    $version,
                    implode(', ', $supportedVersions)
                ),
                0,
                WebapiException::HTTP_NOT_ACCEPTABLE
            );
        }

        return $handlers[$version];
    }

    protected function setNegotiatedVersion(int $version): void
    {
        $this->versionContext->setNegotiatedVersion($version);
    }

    protected function errorResponse(ProblemDetails $problem): string
    {
        $this->response->setHttpResponseCode($problem->getStatus());
        $this->versionContext->setError(true);

        return json_encode($problem);
    }

    private function extractVersionFromHeader(string $headerName): ?int
    {
        $headerValue = $this->request->getHeader($headerName);

        if (!$headerValue || !is_string($headerValue)) {
            return null;
        }

        if (preg_match(self::VERSION_PATTERN, $headerValue, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Extract all version parameters from a header value (e.g. Accept can list multiple).
     *
     * @return int[]
     */
    private function extractAllVersionsFromHeader(string $headerName): array
    {
        $headerValue = $this->request->getHeader($headerName);

        if (!$headerValue || !is_string($headerValue)) {
            return [];
        }

        if (preg_match_all(self::VERSION_PATTERN, $headerValue, $matches)) {
            return array_map('intval', $matches[1]);
        }

        return [];
    }
}
