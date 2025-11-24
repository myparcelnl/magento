<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Sales\Model\Order\Address;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLEuroplus;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLParcelConnect;
use MyParcelNL\Sdk\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Carrier\CarrierTrunkrs;
use MyParcelNL\Sdk\Model\Carrier\CarrierUPSStandard;
use MyParcelNL\Sdk\Model\Carrier\CarrierGLS;

class Config extends AbstractHelper
{
    public const MODULE_NAME                        = 'MyParcelNL_Magento';
    public const PLATFORM                           = 'myparcel';
    public const XML_PATH_MAGENTO_CARRIER           = 'carriers/' . Carrier::CODE . '/';
    public const XML_PATH_GENERAL                   = 'myparcelnl_magento_general/';
    public const XML_PATH_POSTNL_SETTINGS           = 'myparcelnl_magento_postnl_settings/';
    public const XML_PATH_DHLFORYOU_SETTINGS        = 'myparcelnl_magento_dhlforyou_settings/';
    public const XML_PATH_DHLEUROPLUS_SETTINGS      = 'myparcelnl_magento_dhleuroplus_settings/';
    public const XML_PATH_DHLPARCELCONNECT_SETTINGS = 'myparcelnl_magento_dhlparcelconnect_settings/';
    public const XML_PATH_UPS_SETTINGS              = 'myparcelnl_magento_ups_settings/';
    public const XML_PATH_DPD_SETTINGS              = 'myparcelnl_magento_dpd_settings/';
    public const XML_PATH_GLS_SETTINGS              = 'myparcelnl_magento_gls_settings/';
    public const XML_PATH_TRUNKRS_SETTINGS          = 'myparcelnl_magento_trunkrs_settings/';
    public const XML_PATH_LOCALE_WEIGHT_UNIT        = 'general/locale/weight_unit';
    public const FIELD_DROP_OFF_DAY                 = 'drop_off_day';
    public const FIELD_MYPARCEL_CARRIER             = 'myparcel_carrier';
    public const FIELD_DELIVERY_OPTIONS             = 'myparcel_delivery_options';
    public const FIELD_TRACK_STATUS                 = 'track_status';
    public const MYPARCEL_TRACK_TITLE               = 'MyParcel';
    public const EXPORT_MODE_PPS                    = 'pps';
    public const EXPORT_MODE_SHIPMENTS              = 'shipments';

    public const CARRIERS_XML_PATH_MAP
        = [
            CarrierPostNL::NAME           => self::XML_PATH_POSTNL_SETTINGS,
            CarrierDHLForYou::NAME        => self::XML_PATH_DHLFORYOU_SETTINGS,
            CarrierDHLEuroplus::NAME      => self::XML_PATH_DHLEUROPLUS_SETTINGS,
            CarrierDHLParcelConnect::NAME => self::XML_PATH_DHLPARCELCONNECT_SETTINGS,
            CarrierUPSStandard::NAME      => self::XML_PATH_UPS_SETTINGS,
            CarrierDPD::NAME              => self::XML_PATH_DPD_SETTINGS,
            CarrierGLS::NAME              => self::XML_PATH_GLS_SETTINGS,
            CarrierTrunkrs::NAME          => self::XML_PATH_TRUNKRS_SETTINGS,
        ];

    private ModuleListInterface   $moduleList;
    private ?int                  $storeId;

    /**
     * @param Context               $context
     * @param StoreManagerInterface $storeManager
     * @param Session               $authSession
     * @param ModuleListInterface   $moduleList
     */
    public function __construct(
        Context               $context,
        StoreManagerInterface $storeManager,
        Session               $authSession,
        ModuleListInterface   $moduleList
    )
    {
        parent::__construct($context);
        $this->moduleList            = $moduleList;

        try {
            // contrary to documentation store->getId() does not always return an int, so please cast it here
            $this->storeId = (int) $storeManager->getStore()->getId(); // non-admin
        } catch (\Exception $e) {
            $this->storeId = null;
        }

        if ($authSession->isLoggedIn() && 0 !== ($storeIdParam = $context->getRequest()->getParam('store', 0))) {
            $this->storeId = (int) $storeIdParam; // only for admin ($authSession) can we get store id from request
        }
    }

    /**
     * Get settings by field
     *
     * @param string   $field
     * @param int|null $storeId falls back to storeId based on context when null
     * @return mixed
     */
    public function getConfigValue(string $field, ?int $storeId = null)
    {
        return $this->scopeConfig->getValue($field, ScopeInterface::SCOPE_STORES, $storeId ?? $this->storeId);
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

    public function getFloatConfig(string $path, string $key): float
    {
        return (float) $this->getConfigValue("$path$key");
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
     * @param string $path
     * @param string $key
     * @return int
     */
    public function getIntegerConfig(string $path, string $key): int
    {
        return (int) $this->getConfigValue("$path$key");
    }

    /**
     * Get setting for carrier
     *
     * @param string $carrier
     * @param string $code
     * @return mixed
     */
    public function getCarrierConfig(string $carrier, string $code = '')
    {
        $path = self::CARRIERS_XML_PATH_MAP[$carrier] ?? null;

        if (null === $path) {
            return null;
        }

        return $this->getConfigValue("$path$code");
    }


    /**
     * Get general settings
     *
     * @param string   $code
     * @param null|int $storeId fallback to storeId based on context when null
     *
     * @return mixed
     */
    public function getGeneralConfig(string $code = '', ?int $storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_GENERAL . $code, $storeId);
    }

    public function getMagentoCarrierConfig(string $code = '')
    {
        return $this->getConfigValue(self::XML_PATH_MAGENTO_CARRIER . $code);
    }

    /**
     * @return string|null
     */
    public function getExportMode(): ?string
    {
        return $this->getGeneralConfig('print/export_mode');
    }

    /**
     * @param Address|Magento\Quote\Model\Quote\Address\Interceptor|null $address
     * @return string the carrier name configured for this address
     */
    public function getDefaultCarrierName($address): string
    {
        return 'postnl';
        // todo make config value that allows carriers per country / region / etc, select it here based on address.
        return $this->getConfigValue(self::XML_PATH_GENERAL . "default_carrier/$country");
    }

    /**
     * Get the version number of the installed module
     *
     * @return string
     */
    public function getVersion(): string
    {
        $moduleInfo = $this->moduleList->getOne(self::MODULE_NAME);

        return (string) $moduleInfo['setup_version'];
    }
}
