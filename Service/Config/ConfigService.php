<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Config;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLEuroplus;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLParcelConnect;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierUPS;
use MyParcelNL\Sdk\src\Services\CheckApiKeyService;
use MyParcelNL\Sdk\src\Services\Web\CheckApiKeyWebService;

class ConfigService extends AbstractHelper
{
    public const MODULE_NAME = 'MyParcelNL_Magento';
    public const XML_PATH_GENERAL = 'myparcelnl_magento_general/';
    public const XML_PATH_POSTNL_SETTINGS = 'myparcelnl_magento_postnl_settings/';
    public const XML_PATH_DHLFORYOU_SETTINGS = 'myparcelnl_magento_dhlforyou_settings/';
    public const XML_PATH_DHLEUROPLUS_SETTINGS = 'myparcelnl_magento_dhleuroplus_settings/';
    public const XML_PATH_DHLPARCELCONNECT_SETTINGS = 'myparcelnl_magento_dhlparcelconnect_settings/';
    public const XML_PATH_UPS_SETTINGS = 'myparcelnl_magento_ups_settings/';
    public const XML_PATH_DPD_SETTINGS = 'myparcelnl_magento_dpd_settings/';
    public const XML_PATH_LOCALE_WEIGHT_UNIT = 'general/locale/weight_unit';
    public const FIELD_DROP_OFF_DAY = 'drop_off_day';
    public const FIELD_MYPARCEL_CARRIER = 'myparcel_carrier';
    public const FIELD_DELIVERY_OPTIONS = 'myparcel_delivery_options';
    public const FIELD_TRACK_STATUS = 'track_status';
    public const DEFAULT_COUNTRY_CODE = 'NL';
    public const MYPARCEL_TRACK_TITLE  = 'MyParcel';
    public const MYPARCEL_CARRIER_CODE = 'myparcel';
    public const EXPORT_MODE_PPS       = 'pps';
    public const EXPORT_MODE_SHIPMENTS = 'shipments';

    public const CARRIERS_XML_PATH_MAP = [
        CarrierPostNL::NAME => self::XML_PATH_POSTNL_SETTINGS,
        CarrierDHLForYou::NAME => self::XML_PATH_DHLFORYOU_SETTINGS,
        CarrierDHLEuroplus::NAME => self::XML_PATH_DHLEUROPLUS_SETTINGS,
        CarrierDHLParcelConnect::NAME => self::XML_PATH_DHLPARCELCONNECT_SETTINGS,
        CarrierUPS::NAME => self::XML_PATH_UPS_SETTINGS,
        CarrierDPD::NAME => self::XML_PATH_DPD_SETTINGS,
    ];

    /**
     * @var ModuleListInterface
     */
    private $moduleList;
    /**
     * @var CheckApiKeyWebService
     */
    private $checkApiKeyWebService;

    /**
     * @param Context $context
     * @param ModuleListInterface $moduleList
     * @param CheckApiKeyWebService $checkApiKeyWebService
     */
    public function __construct(
        Context               $context,
        ModuleListInterface   $moduleList,
        CheckApiKeyWebService $checkApiKeyWebService
    )
    {
        parent::__construct($context);
        $this->moduleList = $moduleList;
        $this->checkApiKeyWebService = $checkApiKeyWebService;
    }

    /**
     * Get settings by field
     *
     * @param       $field
     * @param null $storeId
     *
     * @return mixed
     */
    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue($field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @param string $path
     * @param string $key
     * @return bool
     */
    public function getBoolConfig(string $path, string $key): bool
    {
        return '1' === $this->getConfigValue("$path$key");
    }

    public function getFloatConfig($path, $key): float
    {
        return (float)$this->getConfigValue("$path$key");
    }

    public function getTimeConfig(string $carrier, string $key): string
    {
        $timeAsString   = str_replace(',', ':', (string) $this->getConfigValue("$carrier$key"));
        $timeComponents = explode(':', $timeAsString ?? '');
        if (count($timeComponents) >= 3) {
            [$hours, $minutes] = $timeComponents;
            $timeAsString = $hours . ':' . $minutes;
        }

        return $timeAsString;
    }

    /**
     * @param $path
     * @param $key
     * @return int
     */
    public function getIntegerConfig($path, $key): int
    {
        return (int)$this->getConfigValue("$path$key");
    }

    /**
     * Get setting for carrier
     *
     * @param string $carrier
     * @param string $code
     * @param null $storeId
     *
     * @return mixed
     */
    public function getCarrierConfig(string $carrier, string $code = '', $storeId = null)
    {
        return $this->getConfigValue(self::CARRIERS_XML_PATH_MAP[$carrier] . $code, $storeId);
    }


    /**
     * Get general settings
     *
     * @param string $code
     * @param null|int $storeId
     *
     * @return mixed
     */
    public function getGeneralConfig(string $code = '', int $storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_GENERAL . $code, $storeId);
    }

    /**
     * @return string|null
     */
    public function getExportMode(): ?string
    {
        return $this->getGeneralConfig('print/export_mode');
    }

    // TODO everything below here must be refactored out

    /**
     * Get the version number of the installed module
     *
     * @return string
     */
    public function getVersion(): string
    {
        $moduleCode = self::MODULE_NAME;
        $moduleInfo = $this->moduleList->getOne($moduleCode);

        return (string)$moduleInfo['setup_version'];
    }

    /**
     * Check if api key is correct
     */
    public function apiKeyIsCorrect(): bool
    {
        $apiKey = $this->getApiKey();

        return $this->checkApiKeyWebService->setApiKey($apiKey)
            ->apiKeyIsCorrect();
    }

    /**
     * @return null|string
     */
    public function getApiKey(): ?string
    {
        return $this->getGeneralConfig('api/key');
    }

    /**
     * Check if global API Key isset
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        $apiKey = $this->getApiKey();

        return isset($apiKey);
    }

    /**
     * Get date in YYYY-MM-DD HH:MM:SS format
     *
     * @param string|null $date
     *
     * @return string|null
     */
    public function convertDeliveryDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        $checkoutDate = json_decode($date, true)['date'] ?? substr($date, 0, 10);
        $deliveryDate = strtotime(date('Y-m-d', strtotime($checkoutDate)));
        $currentDate = strtotime(date('Y-m-d'));

        if ($deliveryDate <= $currentDate) {
            return date('Y-m-d H:i:s', strtotime('now +1 day'));
        }

        return $checkoutDate;
    }

    /**
     * @param int $orderId
     * @param string $status
     */
    public function setOrderStatus(int $orderId, string $status): void
    {
        $order = ObjectManager::getInstance()
            ->create('\Magento\Sales\Model\Order')
            ->load($orderId);
        $order->setState($status)
            ->setStatus($status);
        $order->save();
    }
}
