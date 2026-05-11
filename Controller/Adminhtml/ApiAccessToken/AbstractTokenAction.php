<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ApiAccessToken;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

abstract class AbstractTokenAction extends Action
{
    public const ADMIN_RESOURCE = 'MyParcelNL_Magento::myparcelnl_magento_api_access_token';

    protected JsonFactory  $jsonFactory;
    protected TokenService $tokenService;

    public function __construct(
        Context      $context,
        JsonFactory  $jsonFactory,
        TokenService $tokenService
    ) {
        parent::__construct($context);
        $this->jsonFactory  = $jsonFactory;
        $this->tokenService = $tokenService;
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected function scopeAndId(): array
    {
        $params = $this->getRequest()->getParams();
        return [
            (string) ($params['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT),
            (int) ($params['scopeId'] ?? 0),
        ];
    }

    protected function errorJson(int $httpStatus, string $message): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($httpStatus);
        $result->setData([
            'success' => false,
            'message' => $message,
        ]);
        return $result;
    }
}
