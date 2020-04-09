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

class PackageRepository extends Package
{
    const DEFAULT_MAILBOX_WEIGHT       = 2000;
    const DEFAULT_DIGITAL_STAMP_WEIGHT = 2000;

    /**
     * Get package type
     *
     * If package type is not set, calculate package type
     *
     * @return int 1|3
     */
    public function getPackageType()
    {
        // return type if type is set
        if (parent::getPackageType() !== null) {
            return parent::getPackageType();
        }

        return parent::getPackageType();
    }


    public function isMailboxPackage($quote)
    {
        $active        = $quote['active'];
        $weight        = $this->getMailboxWeight($quote['cart']);
        $country       = $quote['country'];
        $defaultWeight = $quote['defaultWeight'];
        $allPoductsfit = $this->isAllProductsFitInMailbox($quote['cart']);

        return $this->fitInMailbox($active, $country, $weight, $defaultWeight, $allPoductsfit);
    }

    /**
     * @param bool   $active
     * @param string $country
     * @param int    $weight
     * @param int    $defaultWeight
     * @param bool   $allPoductsfit
     *
     * @return bool
     */
    public function fitInMailbox(bool $active, string $country, int $weight, int $defaultWeight, bool $allPoductsfit): bool
    {

        if ($country !== 'NL') {
            return false;
        }

        if (! $active) {
            return false;
        }

        if (! $allPoductsfit) {
            return false;
        }

        if (! $weight) {
            return false;
        }

        if ($allPoductsfit) {
            if ($weight > $defaultWeight) {
                return false;
            }
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

    /**
     * Set weight depend on product weight from product
     *
     * @param \Magento\Quote\Model\Quote\Item[] $products
     *
     * @return $this
     */
    public function setWeightFromQuoteProducts($products)
    {
        if (empty($products)) {
            return $this;
        }

        $this->addWeight(0);
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

//        alles multi carrier maken :
//        if (array_key_exists($carrier, Data::CARRIERS_XML_PATH_MAP)) {
//            return Data::CARRIERS_XML_PATH_MAP[$carrier];
//        }
        $settings = $this->getConfigValue(self::XML_PATH_POSTNL_SETTINGS . 'mailbox');

        if ($settings === null) {
            $this->_logger->critical('Can\'t set settings with path:' . self::XML_PATH_POSTNL_SETTINGS . 'mailbox');
        }

        if (! key_exists('active', $settings)) {
            $this->_logger->critical('Can\'t get mailbox setting active');
        }

        $this->setMailboxActive($settings['active'] === '1');
        if ($this->isMailboxActive() === true) {
            $this->setMaxWeight((int) $settings['weight'] ?: self::DEFAULT_MAILBOX_WEIGHT);
        }

        return $this;
    }

    public function getMailboxWeight($products)
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
     * @return bool
     */
    public function isAllProductsFitInMailbox($products): bool
    {
        $fitInMailbox = 0;
        foreach ($products as $item) {
            $fitInMailbox += ($this->getAttributesProductsOptions($item, 'fit_in_mailbox') * $item->getQty());

            if ($fitInMailbox > 100) {
                return false;
            }
        }

        return true;
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
        if ($this->isDigitalStampActive() === true) {
            $this->setMaxWeight((int) self::DEFAULT_DIGITAL_STAMP_WEIGHT);
        }

        return $this;
    }

    /**
     * Set weight depend on product setting 'Fit in digital stamp' and weight from product
     *
     * @param \Magento\Quote\Model\Quote\Item[] $products
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
     * @param \Magento\Quote\Model\Quote\Item $product
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
     * @param string                          $tableName
     * @param \Magento\Quote\Model\Quote\Item $product
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
            ->getValueFromAttribute($connection, $resource->getTableName($tableName),
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
