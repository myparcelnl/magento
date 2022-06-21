<?php
/**
 * This class contain all functions to check type of package
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelNL\Magento\Model\Sales\Repository;

use MyParcelNL\Magento\Model\Sales\Package;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class PackageRepository extends Package
{
    public const DEFAULT_MAXIMUM_MAILBOX_WEIGHT = 2000;
    public const MAXIMUM_DIGITAL_STAMP_WEIGHT   = 2000;
    public const DEFAULT_LARGE_FORMAT_WEIGHT    = 2300;

    /**
     * @var bool
     */
    public $deliveryOptionsDisabled = false;

    /**
     * @var bool
     */
    public $isPackage = true;

    /**
     * Get package type
     *
     * If package type is not set, calculate package type
     *
     * @return int 1|3
     */
    public function getPackageType(): int
    {
        // return type if type is set
        if (parent::getPackageType() !== null) {
            return parent::getPackageType();
        }

        return parent::getPackageType();
    }

    /**
     * @param array  $products
     * @param string $carrierPath
     *
     * @return string
     */
    public function selectPackageType(array $products, string $carrierPath): string
    {
        // When age check is enabled, only packagetype 'package' is possible
        if ($this->getAgeCheck($products, $carrierPath)) {
            return AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
        }

        $packageType = [];

        if ($this->isMailboxActive() || $this->isDigitalStampActive()) {
            foreach ($products as $product) {
                $digitalStamp = $this->getAttributesProductsOptions($product, 'digital_stamp');
                $mailbox      = $this->getAttributesProductsOptions($product, 'fit_in_mailbox');
                $isPackage    = true;

                if ($digitalStamp && $this->fitInDigitalStamp()) {
                    $packageType[] = AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP;
                    $isPackage     = false;
                    continue;
                }

                if (isset($mailbox) && $this->fitInMailbox($product, $mailbox)) {
                    $packageType[] = AbstractConsignment::PACKAGE_TYPE_MAILBOX;
                    $isPackage     = false;
                    continue;
                }

                if ($isPackage) {
                    $packageType[] = AbstractConsignment::PACKAGE_TYPE_PACKAGE;
                    break;
                }
            }
        }

        // Sort an array in reverse order, so that the largest package type appears at the bottom of the array
        rsort($packageType);

        $packageType      = array_pop($packageType);
        $packageTypeNames = array_flip(AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP);

        return $packageTypeNames[$packageType] ?? AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
    }

    /**
     * @param array $products
     *
     * @return \MyParcelNL\Magento\Model\Sales\Repository\PackageRepository
     */
    public function productWithoutDeliveryOptions(array $products): PackageRepository
    {
        foreach ($products as $product) {
            $this->isDeliveryOptionsDisabled($product);
        }

        return $this;
    }

    /**
     * @param object $product
     * @param int    $mailbox
     *
     * @return bool
     */
    public function fitInMailbox($product, int $mailbox): bool
    {
        $productPercentage = 100 / $mailbox * $product->getQty();

        $mailboxPercentage    = $this->getMailboxPercentage() + $productPercentage;
        $maximumMailboxWeight = $this->getWeightTypeOfOption($this->getMaxMailboxWeight());
        $orderWeight          = $this->getWeightTypeOfOption($this->getWeight());
        if (
            $this->getCurrentCountry() === AbstractConsignment::CC_NL &&
            $this->isMailboxActive() &&
            $orderWeight &&
            ($mailboxPercentage === 0 || $mailboxPercentage <= 100) &&
            $orderWeight <= $maximumMailboxWeight
        ) {
            $this->setMailboxPercentage($mailboxPercentage);

            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function fitInDigitalStamp(): bool
    {
        $orderWeight               = $this->getWeightTypeOfOption($this->getWeight());
        $maximumDigitalStampWeight = $this->getMaxDigitalStampWeight();

        if (
            $this->getCurrentCountry() === AbstractConsignment::CC_NL &&
            $this->isDigitalStampActive() &&
            $orderWeight <= $maximumDigitalStampWeight
        ) {
            return true;
        }
        return false;
    }

    /**
     * Set weight depend on product weight from product
     *
     * @param \Magento\Quote\Model\Quote\Item[] $products
     *
     * @return $this
     */
    public function setWeightFromQuoteProducts(array $products)
    {
        if (empty($products)) {
            return $this;
        }

        foreach ($products as $product) {
            $this->setWeightFromOneQuoteProduct($product);
        }

        return $this;
    }

    /**
     * Init all mailbox settings
     *
     * @return $this
     */
    public function setMailboxSettings()
    {
        $settings = $this->getConfigValue(self::XML_PATH_POSTNL_SETTINGS . 'mailbox');

        if ($settings === null) {
            $this->_logger->critical('Can\'t set settings with path:' . self::XML_PATH_POSTNL_SETTINGS . 'mailbox');
        }

        if (! key_exists('active', $settings)) {
            $this->_logger->critical('Can\'t get mailbox setting active');
        }

        $this->setMailboxActive($settings['active'] === '1');
        if ($this->isMailboxActive() === true) {
            $weight = str_replace(',', '.', $settings['weight']);
            $this->setMaxMailboxWeight($weight ?: self::DEFAULT_MAXIMUM_MAILBOX_WEIGHT);
        }

        return $this;
    }

    /**
     * @param $products
     *
     * @return float
     */
    public function getProductsWeight(array $products): float
    {
        $weight = 0;
        foreach ($products as $item) {
            $weight += ($item->getWeight() * $item->getQty());
        }

        return $weight;
    }

    /**
     * @param $products
     *
     * @return \MyParcelNL\Magento\Model\Sales\Repository\PackageRepository
     */
    public function isDeliveryOptionsDisabled($products)
    {
        $deliveryOptionsEnabled = $this->getAttributesProductsOptions($products, 'disable_checkout');

        if ($deliveryOptionsEnabled) {
            $this->deliveryOptionsDisabled = true;
        }

        return $this;
    }

    /**
     * @param array $products
     *
     * @return int|null
     */
    public function getProductDropOffDelay(array $products): ?int
    {
        $highestDropOffDelay = null;

        foreach ($products as $product) {
            $dropOffDelay = $this->getAttributesProductsOptions($product, 'dropoff_delay');

            if ($dropOffDelay > $highestDropOffDelay) {
                $highestDropOffDelay = $dropOffDelay;
            }
        }

        return $highestDropOffDelay > 0 ? $highestDropOffDelay : null;
    }

    /**
     * @param array  $products
     * @param string $carrierPath
     *
     * @return bool
     */
    public function getAgeCheck(array $products, string $carrierPath): bool
    {
        foreach ($products as $product) {
            $productAgeCheck  = (bool) $this->getAttributesProductsOptions($product, 'age_check');

            if ($productAgeCheck) {
                return true;
            }
        }

        return (bool) $this->getConfigValue($carrierPath . 'default_options/age_check_active');
    }

    /**
     * Init all digital stamp settings
     *
     * @return $this
     */
    public function setDigitalStampSettings()
    {
        $settings = $this->getConfigValue(self::XML_PATH_POSTNL_SETTINGS . 'digital_stamp');
        if ($settings === null) {
            $this->_logger->critical('Can\'t set settings with path:' . self::XML_PATH_POSTNL_SETTINGS . 'digital stamp');
        }

        if (! key_exists('active', $settings)) {
            $this->_logger->critical('Can\'t get digital stamp setting active');
        }

        $this->setDigitalStampActive($settings['active'] === '1');
        if ($this->isDigitalStampActive()) {
            $this->setMaxDigitalStampWeight(self::MAXIMUM_DIGITAL_STAMP_WEIGHT);
        }

        return $this;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $product
     * @param string                          $column
     *
     * @return null|int
     */
    private function getAttributesProductsOptions($product, string $column): ?int
    {
        $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_varchar', $product, $column);
        if (empty($attributeValue)) {
            $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_int', $product, $column);
        }

        if (isset($attributeValue)) {
            return (int) $attributeValue;
        }

        return null;
    }

    /**
     * @param string                          $tableName
     * @param \Magento\Quote\Model\Quote\Item $product
     * @param string                          $column
     *
     * @return null|string
     */
    private function getAttributesFromProduct(string $tableName, $product, string $column): ?string
    {
        /**
         * @var \Magento\Catalog\Model\ResourceModel\Product $resourceModel
         */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $resource      = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $entityId      = $product->getProduct()->getEntityId();
        $connection    = $resource->getConnection();

        $attributeId    = $this->getAttributeId($connection, $resource->getTableName('eav_attribute'), $column);
        $attributeValue = $this
            ->getValueFromAttribute(
                $connection,
                $resource->getTableName($tableName),
                $attributeId,
                $entityId
            );

        return $attributeValue;
    }

    /**
     * @param        $connection
     * @param string $tableName
     * @param string $databaseColumn
     *
     * @return mixed
     */
    private function getAttributeId($connection, string $tableName, string $databaseColumn)
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ?', 'myparcel_' . $databaseColumn);

        return $connection->fetchOne($sql);
    }

    /**
     * @param        $connection
     * @param string $tableName
     * @param string $attributeId
     * @param string $entityId
     *
     * @return mixed
     */
    private function getValueFromAttribute($connection, string $tableName, string $attributeId, string $entityId)
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        return $connection->fetchOne($sql);
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $product
     *
     * @return $this
     */
    private function setWeightFromOneQuoteProduct($product)
    {
        if ($product->getWeight() > 0) {
            $this->addWeight($product->getWeight() * $product->getQty());
        } else {
            $this->setAllProductsFit(false);
        }

        return $this;
    }
}
