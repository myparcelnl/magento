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


use MyParcelNL\Magento\Model\Sales\Package;

class PackageRepository extends Package
{
    /**
     * Get package type
     *
     * If package type is not set, calculate package type
     *
     * @return int 1|2|3
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

        if ($this->isAllProductsFit() === false) {
            return false;
        }

        if ($this->getWeight() > $this->getMaxWeight()) {
            return false;
        }

        return true;
    }

    /**
     * Set weight depend on product setting 'Fit in Mailbox' and weight from product
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

        $this->setWeight(0);
        foreach ($products as $product) {
            $this->setWeightFromOneQuoteProduct($product);
        }

        return $this;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $product
     *
     * @return $this
     */
    private function setWeightFromOneQuoteProduct($product)
    {
        $percentageFitInMailbox = $this->getPercentageFitInMailbox($product);
        if ($percentageFitInMailbox > 1) {

            $this->addWeight($this->getMaxWeight() * $percentageFitInMailbox / 100 * $product->getQty());

            return $this;
        }

        if ($product->getWeight() > 0) {
            $this->addWeight($product->getWeight() * $product->getQty());
        } else {
            $this->setAllProductsFit(false);
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

        if (!key_exists('active', $settings)) {
            $this->_logger->critical('Can\'t get mailbox setting active');
        }

        $this->setMailboxActive($settings['active'] === '1');
        if ($this->isMailboxActive() === true) {
            $this->setShowMailboxWithOtherOptions($settings['other_options'] === '1');
            $this->setMaxWeight((int)$settings['weight']);
        }

        return $this;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $product
     *
     * @return null|int
     */
    private function getPercentageFitInMailbox($product)
    {
        $result = $this->getAttributesFromProduct('catalog_product_entity_varchar', $product);

        if (empty($result)) {
            $result = $this->getAttributesFromProduct('catalog_product_entity_int', $product);
        }

        if (isset($result[0]['value']) && (int)$result[0]['value'] > 0) {
            return (int)$result[0]['value'];
        }

        return null;
    }

    /**
     * @Param string $tableName
     * @param \Magento\Quote\Model\Quote\Item $product
     * @return array|null
     */
    private function getAttributesFromProduct($tableName, $product){

        /**
         * @var \Magento\Catalog\Model\ResourceModel\Product $resourceModel
         */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');

        $entityId = $product->getProduct()->getEntityId();
        $resourceModel = $product->getProduct()->getResource();
        $connection = $resource->getConnection();
        $tableName = $resource->getTableName($tableName);
        $attributesHolder = $resourceModel->getSortedAttributes();

        if (!key_exists('myparcel_fit_in_mailbox', $attributesHolder)) {
            return null;
        }

        $attributeId = $attributesHolder['myparcel_fit_in_mailbox']->getData('attribute_id');

        $sql = $connection->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);
        $result = $connection->fetchAll($sql);

        return $result;
    }


}
