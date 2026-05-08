<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ApiAccessToken;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

class Generate extends Action
{
    public const ADMIN_RESOURCE = 'MyParcelNL_Magento::myparcelnl_magento_api_access_token';

    private JsonFactory  $jsonFactory;
    private TokenService $tokenService;

    public function __construct(
        Context      $context,
        JsonFactory  $jsonFactory,
        TokenService $tokenService
    ) {
        parent::__construct($context);
        $this->jsonFactory  = $jsonFactory;
        $this->tokenService = $tokenService;
    }

    public function execute(): ResultInterface
    {
        $params  = $this->getRequest()->getParams();
        $scope   = (string) ($params['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $scopeId = (int) ($params['scopeId'] ?? 0);

        try {
            $token = $this->tokenService->generateForScope($scope, $scopeId);
        } catch (AlreadyExistsException $e) {
            return $this->errorJson(409, $e->getMessage());
        } catch (InputException $e) {
            return $this->errorJson(400, $e->getMessage());
        }

        return $this->jsonFactory->create()->setData([
            'success' => true,
            'token'   => $token,
        ]);
    }

    private function errorJson(int $httpStatus, string $message): Json
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
