<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Block\Version;

use Magento\Framework\Registry;
use Magento\Sales\Helper\Admin;
use MyParcelNL\Magento\Models\Boot;
use MyParcelNL\Pdk\Account\Platform;
use MyParcelNL\Pdk\Base\Pdk as PdkInstance;
use MyParcelNL\Pdk\Facade\Pdk;
use function MyParcelNL\Magento\bootPdk;

class VersionTabRepository extends \Magento\Sales\Block\Adminhtml\Order\AbstractOrder
{
    protected $moduleList;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Helper\Admin $adminHelper,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        Pdk $pdk,
        array $data = []
    ) {
        $this->moduleList = $moduleList;
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
