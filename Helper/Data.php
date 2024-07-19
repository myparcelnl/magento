<?php

namespace MyParcelBE\Magento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelBE\Magento\Model\Checkout\Carrier;
use MyParcelBE\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierBpost;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLEuroplus;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLParcelConnect;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierUPS;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\DropOffPoint;
use MyParcelNL\Sdk\src\Services\CheckApiKeyService;

class Data extends AbstractHelper
{
    public const MODULE_NAME                        = 'MyParcelBE_Magento';
    public const XML_PATH_GENERAL                   = 'myparcelbe_magento_general/';
    public const XML_PATH_POSTNL_SETTINGS           = 'myparcelbe_magento_postnl_settings/';
    public const XML_PATH_DHLFORYOU_SETTINGS        = 'myparcelbe_magento_dhlforyou_settings/';
    public const XML_PATH_DHLEUROPLUS_SETTINGS      = 'myparcelbe_magento_dhleuroplus_settings/';
    public const XML_PATH_DHLPARCELCONNECT_SETTINGS = 'myparcelbe_magento_dhlparcelconnect_settings/';
    public const XML_PATH_UPS_SETTINGS              = 'myparcelbe_magento_ups_settings/';
    public const XML_PATH_DPD_SETTINGS              = 'myparcelbe_magento_dpd_settings/';
    public const XML_PATH_BPOST_SETTINGS            = 'myparcelbe_magento_bpost_settings/';
    public const XML_PATH_LOCALE_WEIGHT_UNIT        = 'general/locale/weight_unit';
    public const DEFAULT_WEIGHT                     = 1000;
    public const CARRIERS_XML_PATH_MAP              = [
        CarrierPostNL::NAME           => self::XML_PATH_POSTNL_SETTINGS,
        CarrierDHLForYou::NAME        => self::XML_PATH_DHLFORYOU_SETTINGS,
        CarrierDHLEuroplus::NAME      => self::XML_PATH_DHLEUROPLUS_SETTINGS,
        CarrierDHLParcelConnect::NAME => self::XML_PATH_DHLPARCELCONNECT_SETTINGS,
        CarrierUPS::NAME              => self::XML_PATH_UPS_SETTINGS,
        CarrierDPD::NAME              => self::XML_PATH_DPD_SETTINGS,
        CarrierBpost::NAME            => self::XML_PATH_BPOST_SETTINGS,
    ];

    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    private $moduleList;

    /**
     * @var CheckApiKeyService
     */
    private $checkApiKeyService;

    /**
     * Get settings by field
     *
     * @param  Context             $context
     * @param  ModuleListInterface $moduleList
     * @param  CheckApiKeyService  $checkApiKeyService
     */
    public function __construct(
        Context             $context,
        ModuleListInterface $moduleList,
        CheckApiKeyService  $checkApiKeyService
    ) {
        parent::__construct($context);
        $this->moduleList         = $moduleList;
        $this->checkApiKeyService = $checkApiKeyService;
    }

    /**
     * Get settings by field
     *
     * @param       $field
     * @param  null $storeId
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
     * @param  string   $code
     * @param  null|int $storeId
     *
     * @return mixed
     */
    public function getGeneralConfig(string $code = '', int $storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_GENERAL . $code, $storeId);
    }

    /**
     * @throws \Exception
     */
    public function getDropOffPoint(AbstractCarrier $carrier): ?DropOffPoint
    {
        $accountSettings      = AccountSettings::getInstance();
        $carrierConfiguration = $accountSettings->getCarrierConfigurationByCarrier($carrier);

        if (! $carrierConfiguration) {
            return null;
        }

        $dropOffPoint = $carrierConfiguration->getDefaultDropOffPoint();

        if ($dropOffPoint && null === $dropOffPoint->getNumberSuffix()) {
            $dropOffPoint->setNumberSuffix('');
        }

        return $dropOffPoint;
    }

    /**
     * Get default settings
     *
     * @param  string $carrier
     * @param  string $code
     * @param  null   $storeId
     *
     * @return mixed
     */
    public function getStandardConfig(string $carrier, string $code = '', $storeId = null)
    {
        return $this->getConfigValue(self::CARRIERS_XML_PATH_MAP[$carrier] . $code, $storeId);
    }

    /**
     * Get carrier setting
     *
     * @param  string $code
     * @param  string $carrier
     *
     * @return mixed
     */
    public function getCarrierConfig(string $code, string $carrier)
    {
        $settings = $this->getConfigValue($carrier . $code);

        if (null === $settings) {
            $value = $this->getConfigValue($carrier . $code);

            if (null === $value) {
                $this->_logger->critical('Can\'t get setting with path:' . $carrier . $code);
            }

            return $value;
        }

        return $settings;
    }

    /**
     * Get the version number of the installed module
     *
     * @return string
     */
    public function getVersion(): string
    {
        $moduleCode = self::MODULE_NAME;
        $moduleInfo = $this->moduleList->getOne($moduleCode);

        return (string) $moduleInfo['setup_version'];
    }

    /**
     * Check if api key is correct
     */
    public function apiKeyIsCorrect(): bool
    {
        $apiKey = $this->getApiKey();

        return $this->checkApiKeyService->setApiKey($apiKey)
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
     * @param  string|null $date
     *
     * @return string|null
     */
    public function convertDeliveryDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        $checkoutDate = json_decode($date, true)['date'] ?? substr($date, 0, 10);
        $deliveryDate = strtotime(date('Y-m-d', strtotime($checkoutDate)));
        $currentDate  = strtotime(date('Y-m-d'));

        if ($deliveryDate <= $currentDate) {
            return date('Y-m-d H:i:s', strtotime('now +1 day'));
        }

        return $checkoutDate;
    }

    /**
     * Get delivery type and when it is null use 'standard'
     *
     * @param  int|null $deliveryType
     *
     * @return int
     */
    public function checkDeliveryType(?int $deliveryType): int
    {
        if (! $deliveryType) {
            return AbstractConsignment::DELIVERY_TYPE_STANDARD;
        }

        return $deliveryType;
    }

    /**
     * @param  int    $orderId
     * @param  string $status
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

    public function consignmentHasShipmentOption(AbstractConsignment $consignment, string $shipmentOption): bool
    {
        /**
         * Business logic determining what shipment options to show, if any.
         */
        if (AbstractConsignment::CC_NL === $consignment->getCountry()) {
            return $consignment->canHaveShipmentOption($shipmentOption);
        }

        // For PostNL in Belgium - only recipient-only/signature is available
        if (AbstractConsignment::CC_BE === $consignment->getCountry() && CarrierPostNL::NAME === $consignment->getCarrierName()) {
            return in_array($shipmentOption, [
                AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT,
                AbstractConsignment::SHIPMENT_OPTION_SIGNATURE], true);
        }

        // No shipment options available in any other cases
        return false;
    }

    /**
     * Get the correct weight type
     *
     * @param  null|float $weight
     *
     * @return int
     */
    public function convertToGrams(?float $weight): int
    {
        $weightType = $this->getGeneralConfig('print/weight_indication');

        if ('kilo' === $weightType) {
            return (int) ($weight * 1000);
        }

        return (int) $weight ?: self::DEFAULT_WEIGHT;
    }

    /**
     * @return string|null
     */
    public function getExportMode(): ?string
    {
        return $this->getGeneralConfig('print/export_mode');
    }
}
