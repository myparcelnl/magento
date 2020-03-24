<?php
/**
 * This class contain all functions to check type of package
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe/magento
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelBE\Magento\Model\Sales\Repository;


use MyParcelBE\Magento\Model\Sales\Package;

class PackageRepository extends Package
{
	const DEFAULT_WEIGHT = 2000;

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
        if ($product->getWeight() > 0) {
            $this->addWeight($product->getWeight() * $product->getQty());
        } else {
            $this->setAllProductsFit(false);
        }

        return $this;
    }
}
