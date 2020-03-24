<?php
/**
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Sdk\src\Model\Consignment\BpostConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\DPDConsignment;
use MyParcelNL\Sdk\src\Services\CheckApiKeyService;

class Data extends AbstractHelper
{
    const MODULE_NAME             = 'MyParcelBE_Magento';
    const XML_PATH_GENERAL        = 'myparcelbe_magento_general/';
    const XML_PATH_BPOST_SETTINGS = 'myparcelbe_magento_bpost_settings/';
    const XML_PATH_DPD_SETTINGS   = 'myparcelbe_magento_dpd_settings/';

    public const CARRIERS = [BpostConsignment::CARRIER_NAME, DPDConsignment::CARRIER_NAME];

    public const CARRIERS_XML_PATH_MAP = [
        BpostConsignment::CARRIER_NAME => Data::XML_PATH_BPOST_SETTINGS,
        DPDConsignment::CARRIER_NAME   => Data::XML_PATH_DPD_SETTINGS,
    ];

    private $moduleList;

    /**
     * @var CheckApiKeyService
     */
    private $checkApiKeyService;

    /**
     * Get settings by field
     *
     * @param Context $context
     * @param ModuleListInterface $moduleList
     * @param CheckApiKeyService $checkApiKeyService
     */
    public function __construct(
        Context $context,
        ModuleListInterface $moduleList,
        CheckApiKeyService $checkApiKeyService
    ) {
        parent::__construct($context);
        $this->moduleList = $moduleList;
        $this->checkApiKeyService = $checkApiKeyService;
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
        return $this->getConfigValue(self::XML_PATH_BPOST_SETTINGS . $code, $storeId);
    }

    /**
     * Get checkout setting
     *
     * @param string $code
     * @param null   $storeId
     *
     * @return mixed
     */
    public function getCarrierConfig($code, $storeId = null)
    {
        $settings = $this->getTmpScope();
        if ($settings == null) {
            $value = $this->getConfigValue(self::XML_PATH_BPOST_SETTINGS . $code);
            if ($value != null) {
                return $value;
            } else {
                $this->_logger->critical('Can\'t get setting with path:' . self::XML_PATH_BPOST_SETTINGS . $code);
            }
        }

        if (!is_array($settings)) {
            $this->_logger->critical('No data in settings array');
        }

        if (!key_exists($code, $settings)) {
            $this->_logger->critical('Can\'t get setting ' . $code);
        }

        return $settings[$code];
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

    /**
     * Check if api key is correct
     */
    public function apiKeyIsCorrect()
    {
        $defaultApiKey = $this->getGeneralConfig('api/key');
        $keyIsCorrect = $this->checkApiKeyService->setApiKey($defaultApiKey)->apiKeyIsCorrect();

        return $keyIsCorrect;
    }
}
