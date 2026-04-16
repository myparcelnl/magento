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
     * @return object[]
     */
    abstract protected function getRequestHandlers(): array;

    /**
     * Subclass returns [1 => ResourceV1::class, 2 => ResourceV2::class, ...].
     *
     * @return array<int, class-string<AbstractVersionedResource>>
     */
    abstract protected function getResourceHandlers(): array;

    /**
     * Negotiate request and response versions independently per ADR 0011.
     *
     * - §4.1: Content-Type version drives the request; defaults to min(requestSupported).
     * - §4.2: Accept version drives the response; defaults to the Content-Type version.
     * - §5.2: Content-Type version must appear in Accept list when both are present (409).
     * - §5.1: unsupported versions trigger 406.
     *
     * @return object The request handler for the negotiated request version.
     * @throws WebapiException 406 if a version is unsupported, 409 if Content-Type and Accept conflict
     */
    protected function negotiate(): object
    {
        $requestHandlers  = $this->getRequestHandlers();
        $resourceHandlers = $this->getResourceHandlers();
        $reqSupported     = array_keys($requestHandlers);
        $resSupported     = array_keys($resourceHandlers);

        $this->versionContext->setSupportedRequestVersions($reqSupported);
        $this->versionContext->setSupportedResponseVersions($resSupported);

        $contentTypeVersion = $this->extractVersionFromHeader('Content-Type');
        $acceptVersions     = $this->extractAllVersionsFromHeader('Accept');

        // §5.2: 409 when Content-Type version is not listed in Accept versions
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

        // §4.1: request version from Content-Type, default min(requestSupported)
        $requestVersion = $contentTypeVersion ?? min($reqSupported);

        if (!isset($requestHandlers[$requestVersion])) {
            throw new WebapiException(
                __('Request version %1 is not supported. Supported request versions: %2.',
                    $requestVersion,
                    implode(', ', $reqSupported)
                ),
                0,
                WebapiException::HTTP_NOT_ACCEPTABLE
            );
        }

        // §4.2: response version from Accept, default Content-Type version (resolved above)
        $responseVersion = $acceptVersions[0] ?? $requestVersion;

        if (!isset($resourceHandlers[$responseVersion])) {
            throw new WebapiException(
                __('Response version %1 is not supported. Supported response versions: %2.',
                    $responseVersion,
                    implode(', ', $resSupported)
                ),
                0,
                WebapiException::HTTP_NOT_ACCEPTABLE
            );
        }

        $this->versionContext->setNegotiatedRequestVersion($requestVersion);
        $this->versionContext->setNegotiatedResponseVersion($responseVersion);

        return $requestHandlers[$requestVersion];
    }

    /**
     * Instantiate the resource class for the negotiated response version.
     * Must be called after negotiate().
     */
    protected function createResource(array $data): AbstractVersionedResource
    {
        $version = $this->versionContext->getNegotiatedResponseVersion();
        $class   = $this->getResourceHandlers()[$version];

        return new $class($data);
    }

    protected function errorResponse(ProblemDetails $problem): string
    {
        $this->response->setHttpResponseCode($problem->getStatus());
        $this->versionContext->setError(true);

        return json_encode($problem, JSON_THROW_ON_ERROR);
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
