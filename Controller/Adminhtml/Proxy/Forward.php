<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Proxy;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpDeleteActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpHeadActionInterface;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\Action\HttpPatchActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpPutActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use MyParcelNL\Magento\Service\ApiProxy;

class Forward extends Action implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    HttpPutActionInterface,
    HttpDeleteActionInterface,
    HttpPatchActionInterface,
    HttpHeadActionInterface,
    HttpOptionsActionInterface
{
    public const ADMIN_RESOURCE = 'MyParcelNL_Magento::api_proxy';

    /**
     * The upstream path lives in the URL tail, not in the action name. Mark the
     * action as public so the standard admin URL-key check is skipped (admin
     * session + ACL still gate access; form key still enforced on non-GET).
     *
     * @var string[]
     */
    protected $_publicActions = ['forward'];

    public function __construct(
        Context $context,
        private readonly ApiProxy $apiProxy,
        private readonly RawFactory $rawFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $request = $this->getRequest();

        $path    = (string) $request->getParam('upstream_path');
        $body    = (string) $request->getContent();
        $query   = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $headers = $this->collectRequestHeaders($request);

        $resp = $this->apiProxy->forward($path, $request->getMethod(), $headers, $body, $query);

        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($resp->status);
        foreach ($resp->headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }
        $result->setContents($resp->body);
        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function collectRequestHeaders(RequestInterface $request): array
    {
        $out = [];
        if (!method_exists($request, 'getHeaders')) {
            return $out;
        }
        foreach ($request->getHeaders() as $header) {
            $out[$header->getFieldName()] = $header->getFieldValue();
        }
        return $out;
    }
}
