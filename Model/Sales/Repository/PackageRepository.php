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
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelNL\Magento\Model\Sales\Repository;

use Magento\Quote\Model\Quote\Item;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Model\Sales\Package;

class PackageRepository extends Package
{
    /**
     * @var Checkout
     */
    private $myParcelHelper;

    const DEFAULT_MAILBOX_WEIGHT       = 2000;
    const DEFAULT_DIGITAL_STAMP_WEIGHT = 2000;
    const DISABLED_CHECKOUT_ON         = true;

    /**
     * Get package type
     *
     * If package type is not set, calculate package type
     *
     * @return int 1|2|3|4
     */
    public function getPackageType()
    {
        // return type if type is set
        if (parent::getPackageType() !== null) {
            return parent::getPackageType();
        }

        // Set Mailbox if possible
        if ($this->fitInMailbox() === true) {
            $this->setPackageType(self::PACKAGE_TYPE_MAILBOX);
        }

        // Set digital_stamp if possible
        if ($this->fitInDigitalStamp() === true) {
            $this->setPackageType(self::PACKAGE_TYPE_DIGITAL_STAMP);
        }

        return parent::getPackageType();
    }

    /**
     * @return bool
     */
    public function fitInMailbox()
    {
        if ($this->getCurrentCountry() !== 'NL') {
            return false;
        }

        if ($this->isMailboxActive() === false) {
            return false;
        }

        if ($this->isAllProductsFitInMailbox() === false) {
            return false;
        }

        if ($this->getWeight() == false) {
            return false;
        }

        if ($this->getWeight() > $this->getMaxWeight()) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function fitInDigitalStamp()
    {
        if ($this->getCurrentCountry() !== 'NL') {
            return false;
        }

        if ($this->isDigitalStampActive() === false) {
            return false;
        }

        if ($this->isAllProductsFitInDigitalStamp() === false) {
            return false;
        }

        if ($this->getWeight() > self::DEFAULT_DIGITAL_STAMP_WEIGHT) {
            return false;
        }

        return true;
    }

    public function dropOffDelayDayWithProduct($products)
    {
        if (! $products) {
            return;
        }
    }

    /**
     * @param Item[] $products
     */
    public function disableCheckoutWithProduct($products)
    {
        if (! $products) {
            return;
        }

        foreach ($products as $product) {
            $getDisabledOption = (bool) $this->getAttributesProductsOptions($product, 'disable_checkout');

            if ($getDisabledOption === self::DISABLED_CHECKOUT_ON) {
                $this->setDisableCheckout(true);
                break;
            }
        }

        return;
    }

    /**
     * Set weight depend on product setting 'Fit in digital stamp' and weight from product
     *
     * @param Item[] $products
     *
     * @return $this
     */
    public function setFitInDigitalStampFromQuoteProducts($products)
    {
        if (empty($products)) {
            return $this;
        }

        foreach ($products as $product) {
            if ($this->getAttributesProductsOptions($product, 'digital_stamp') === null) {
                return $this->setAllProductsFitInMailbox(false, 'digital_stamp');
            }
        }

        return $this;
    }

    /**
     * Set weight depend on product setting 'Fit in Mailbox' and weight from product
     *
     * @param Item[] $products
     *
     * @param string                            $column
     *
     * @return $this
     */
    public function setWeightFromQuoteProducts($products, $column)
    {
        if (empty($products)) {
            return $this;
        }

        $this->setWeight(0);
        foreach ($products as $product) {
            $this->setWeightFromOneQuoteProduct($product, $column);
        }

        return $this;
    }

    /**
     * @param Item $product
     * @param string                          $column
     *
     * @return $this
     */
    private function setWeightFromOneQuoteProduct($product, $column)
    {
        if ('fit_in_mailbox' == $column) {
            $percentageFitInMailbox = $this->getAttributesProductsOptions($product, $column);

            if ($percentageFitInMailbox > 1) {
                $this->addWeight($this->getMaxWeight() * $percentageFitInMailbox / 100 * $product->getQty());

                return $this;
            }
        }

        if ($product->getWeight() > 0) {
            $this->addWeight($product->getWeight() * $product->getQty());
        } else {
            $this->setAllProductsFitInMailbox(false);
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
        $settings = $this->getConfigValue(self::XML_PATH_CHECKOUT . 'mailbox');

        if ($settings === null) {
            $this->_logger->critical('Can\'t set settings with path:' . self::XML_PATH_CHECKOUT . 'mailbox');
        }

        if (! key_exists('active', $settings)) {
            $this->_logger->critical('Can\'t get mailbox setting active');
        }

        $this->setMailboxActive($settings['active'] === '1');
        if ($this->isMailboxActive() === true) {
            $this->setShowMailboxWithOtherOptions($settings['other_options'] === '1');
            $this->setMaxWeight((int) $settings['weight'] ?: self::DEFAULT_MAILBOX_WEIGHT);
        }

        return $this;
    }

    /**
     * @param Item $product
     * @param string                          $column
     *
     * @return null|int
     */
    private function getAttributesProductsOptions($product, $column)
    {
        $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_varchar', $product, $column);

        if (empty($attributeValue)) {
            $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_int', $product, $column);
        }

        if ($attributeValue) {
            return (int) $attributeValue;
        }

        return null;
    }

    /**
     * Init all digital stamp settings
     *
     * @return $this
     */
    public function setDigitalStampSettings()
    {
        $settings = $this->getConfigValue(self::XML_PATH_CHECKOUT . 'digital_stamp');
        if ($settings === null) {
            $this->_logger->critical('Can\'t set settings with path:' . self::XML_PATH_CHECKOUT . 'digital stamp');
        }

        if (! key_exists('active', $settings)) {
            $this->_logger->critical('Can\'t get digital stamp setting active');
        }

        $this->setDigitalStampActive($settings['active'] === '1');
        if ($this->isDigitalStampActive() === true) {
            $this->setMaxWeight((int) self::DEFAULT_DIGITAL_STAMP_WEIGHT);
        }

        return $this;
    }

    /**
     * @param string                          $tableName
     * @param Item $product
     * @param string                          $column
     *
     * @return array|null
     */
    private function getAttributesFromProduct($tableName, $product, $column)
    {

        /**
         * @var \Magento\Catalog\Model\ResourceModel\Product $resourceModel
         */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $resource   = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $entityId   = $product->getProduct()->getEntityId();
        $connection = $resource->getConnection();

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

    private function getAttributeId($connection, $tableName, $databaseColumn)
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ?', 'myparcel_' . $databaseColumn);

        return $connection->fetchOne($sql);
    }

    private function getValueFromAttribute($connection, $tableName, $attributeId, $entityId)
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        return $connection->fetchOne($sql);
    }
}
