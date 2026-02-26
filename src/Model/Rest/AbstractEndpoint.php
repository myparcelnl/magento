<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;

abstract class AbstractEndpoint
{
    private const VERSION_PATTERN = '/version=v?(\d+)/i';
    private const DEFAULT_VERSION = 1;

    public const SIGNAL_HEADER       = 'X-MyParcel-Api-Version';
    public const SIGNAL_ERROR_HEADER = 'X-MyParcel-Error';

    private Request  $request;
    private Response $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request  = $request;
        $this->response = $response;
    }

    /**
     * Subclass returns [1 => $v1Handler, 2 => $v2Handler, ...].
     *
     * @return AbstractVersionedRequest[]
     */
    abstract protected function getVersionHandlers(): array;

    /**
     * Detect version from Content-Type → Accept → default 1.
     * Validate against getVersionHandlers().
     *
     * @throws WebapiException 406 if version is unsupported
     */
    protected function resolveVersion(): AbstractVersionedRequest
    {
        $version = $this->extractVersionFromHeader('Content-Type')
            ?? $this->extractVersionFromHeader('Accept')
            ?? self::DEFAULT_VERSION;

        $handlers = $this->getVersionHandlers();

        if (!isset($handlers[$version])) {
            throw new WebapiException(
                __('API version %1 is not supported. Supported versions: %2.',
                    $version,
                    implode(', ', array_keys($handlers))
                ),
                0,
                WebapiException::HTTP_NOT_ACCEPTABLE
            );
        }

        $this->response->setHeader(self::SIGNAL_HEADER, (string) $version);

        return $handlers[$version];
    }

    protected function errorResponse(ProblemDetails $problem): string
    {
        $this->response->setHttpResponseCode($problem->getStatus());
        $this->response->setHeader(self::SIGNAL_ERROR_HEADER, '1');

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
}
