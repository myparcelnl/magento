<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ApiAccessToken;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;

/**
 * Admin action minting a fresh API access token for the active (scope, scopeId).
 *
 * Returns the plaintext exactly once in the JSON response — never re-readable afterwards,
 * since storage holds only the SHA-256 hash. 409 on hash collision against another scope,
 * 400 on an invalid scope name.
 */
class Generate extends AbstractTokenAction implements HttpPostActionInterface
{
    public function execute(): ResultInterface
    {
        [$scope, $scopeId] = $this->scopeAndId();

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
}
