<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Block\Version;

use MyParcelNL\Pdk\Facade\Pdk;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Helper\Admin;
use Magento\Framework\Module\ModuleListInterface as ModuleList;
use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;

class VersionTabRepository extends AbstractOrder
{
    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $moduleList;

    /**
     * @var \MyParcelNL\Pdk\Base\Model\AppInfo
     */
    protected $pdk;

    public function __construct(
        Context $context,
        Registry $registry,
        Admin $adminHelper,
        ModuleList $moduleList,
        Pdk $pdk,
        array $data = []
    ) {
        $this->moduleList = $moduleList;
        $this->pdk = $pdk;
        parent::__construct($context, $registry, $adminHelper, $data);
    }

    /**
     * @return string
     */
    public function getModuleVersion(): string
    {
        return $this->moduleList
            ->getOne('MyParcelNL_Magento')['setup_version'];
    }

    /**
     * @return \MyParcelNL\Pdk\Base\Model\AppInfo
     * @throws \Exception
     */
    public function getPdk(): \MyParcelNL\Pdk\Base\Model\AppInfo
    {
        return Pdk::getAppInfo();
    }
}
