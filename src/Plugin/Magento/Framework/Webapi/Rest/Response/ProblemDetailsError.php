<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Plugin\Magento\Framework\Webapi\Rest\Response;

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use MyParcelNL\Magento\Model\Rest\ProblemDetails;

class ProblemDetailsError
{
    /** @var Request */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Intercept exceptions on MyParcel endpoints and format them as RFC 9457 Problem Details.
     *
     * Skips $proceed() so the exception is not stored on the Response, preventing
     * Magento's _renderMessages() from overwriting our body.
     *
     * @param  Response  $subject
     * @param  callable  $proceed
     * @param  \Exception $exception
     * @return Response
     */
    public function aroundSetException(Response $subject, callable $proceed, $exception)
    {
        if (!$this->isMyParcelEndpoint()) {
            return $proceed($exception);
        }

        $httpCode = $exception instanceof WebapiException ? $exception->getHttpCode() : 500;
        $detail   = $exception instanceof WebapiException
            ? $exception->getMessage()
            : 'An unexpected error occurred';

        $problem = new ProblemDetails(
            null,
            $httpCode,
            ProblemDetails::titleForStatus($httpCode),
            $detail
        );

        $subject->setBody(json_encode($problem));
        $subject->setHttpResponseCode($httpCode);
        $subject->setHeader('Content-Type', ProblemDetails::CONTENT_TYPE, true);

        return $subject;
    }

    private function isMyParcelEndpoint(): bool
    {
        return str_contains($this->request->getPathInfo() ?? '', 'myparcel/');
    }
}
