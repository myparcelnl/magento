<?php
/**
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const MODULE_NAME = 'MyParcelNL_Magento';
    const XML_PATH_GENERAL = 'myparcelnl_magento_general/';
    const XML_PATH_STANDARD = 'myparcelnl_magento_standard/';
    const XML_PATH_CHECKOUT = 'myparcelnl_magento_checkout/';
    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    private $moduleList;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    public $logger;

    /**
     * @param Context $context
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Psr\Log\LoggerInterface $logger
    )
    {
        parent::__construct($context);
        $this->moduleList = $moduleList;
        $this->logger = $logger;
    }

    /**
     * Get settings by field
     *
     * @param      $field
     * @param null $storeId
     *
     * @return mixed
     */
    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue($field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Get general settings
     *
     * @param string $code
     * @param int    $storeId
     *
     * @return mixed
     */
    public function getGeneralConfig($code = '', $storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_GENERAL . $code, $storeId);
    }

    /**
     * Get default settings
     *
     * @param string $code
     * @param null   $storeId
     *
     * @return mixed
     */
    public function getStandardConfig($code = '', $storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_STANDARD . $code, $storeId);
    }

    /**
     * Get the version number of the installed module
     *
     * @return string
     */
    public function getVersion()
    {
        $moduleCode = self::MODULE_NAME;
        $moduleInfo = $this->moduleList->getOne($moduleCode);

        return (string)$moduleInfo['setup_version'];
    }
}
