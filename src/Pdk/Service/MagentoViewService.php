<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Service;

use MyParcelNL\Pdk\Frontend\Service\AbstractViewService;

class MagentoViewService extends AbstractViewService
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private $request;

    /**
     * MagentoViewService constructor.
     *
     * @param \Magento\Framework\App\Request\Http $request
     */
    public function __construct(
        \Magento\Framework\App\Request\Http $request
    )
    {
        $this->request = $request;
    }

    /**
     * @return bool
     */
    public function isCheckoutPage(): bool
    {
        if ($this->request->getFullActionName() === 'checkout_index_index') {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isChildProductPage(): bool
    {
        if ($this->request->getFullActionName() === 'catalog_product_view') {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isOrderListPage(): bool
    {
        if ($this->request->getFullActionName() === 'sales_order_index') {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isOrderPage(): bool
    {
        if ($this->request->getFullActionName() === 'sales_order_view') {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isPluginSettingsPage(): bool
    {
        if ($this->request->getFullActionName() === 'adminhtml_system_config_edit') {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isProductPage(): bool
    {
        if ($this->request->getFullActionName() === 'catalog_product_view') {
            return true;
        }

        return false;
    }
}
