<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ApiAccessToken;

use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\InputException;

class Revoke extends AbstractTokenAction
{
    public function execute(): ResultInterface
    {
        [$scope, $scopeId] = $this->scopeAndId();

        try {
            $this->tokenService->revokeForScope($scope, $scopeId);
        } catch (InputException $e) {
            return $this->errorJson(400, $e->getMessage());
        }

        return $this->jsonFactory->create()->setData(['success' => true]);
    }
}
