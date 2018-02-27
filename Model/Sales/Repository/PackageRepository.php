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
	const DEFAULT_WEIGHT = 2000;

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

	    if ($this->getWeight() == false) {
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
            $this->setMaxWeight((int)$settings['weight'] ?: self::DEFAULT_WEIGHT);
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
        $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_varchar', $product);

        if (empty($attributeValue)) {
            $attributeValue = $this->getAttributesFromProduct('catalog_product_entity_int', $product);
        }

        if ($attributeValue) {
            return (int)$attributeValue;
        }

        return null;
    }

	/**
	 * @Param string $tableName
	 *
	 * @param string $tableName
	 * @param \Magento\Quote\Model\Quote\Item $product
	 *
	 * @return array|null
	 */
    private function getAttributesFromProduct($tableName, $product){

        /**
         * @var \Magento\Catalog\Model\ResourceModel\Product $resourceModel
         */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $entityId = $product->getProduct()->getEntityId();
        $connection = $resource->getConnection();

	    $attributeId = $this->getAttributeId($connection, $resource->getTableName('eav_attribute'));
	    $attributeValue = $this
		    ->getValueFromAttribute(
		    	$connection,
			    $resource->getTableName($tableName),
			    $attributeId,
			    $entityId
		    );

        return $attributeValue;
    }

	private function getAttributeId($connection, $tableName) {
		$sql = $connection
			->select('entity_type_id')
		    ->from($tableName)
		    ->where('attribute_code = ?', 'myparcel_fit_in_mailbox');

		return $connection->fetchOne($sql);
	}

	private function getValueFromAttribute( $connection, $tableName, $attributeId, $entityId ) {

		$sql = $connection
			->select()
			->from($tableName, ['value'])
			->where('attribute_id = ?', $attributeId)
			->where('entity_id = ?', $entityId);

		return $connection->fetchOne($sql);
	}
}
