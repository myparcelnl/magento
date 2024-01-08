<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Plugin\Action;

use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Pdk\App\Api\Backend\AbstractPdkBackendEndpointService;

class MagentoBackendEndpointService extends AbstractPdkBackendEndpointService
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        StoreManagerInterface $storeManager
    ) {
        $this->storeManager = $storeManager;
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getBaseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl();
    }
}
