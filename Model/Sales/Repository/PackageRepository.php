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
     * @param \Magento\Quote\Model\Quote\Item[] $products
     *
     * @return $this
     */
    public function setWeightFromQuoteProducts($products)
    {
        foreach ($products as $product) {
            if ($product->getWeight() > 0) {
                $this->addWeight($product->getWeight());
            } else {
                $this->setAllProductsFit(false);
            }
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
            $this->logger->critical('Can\'t set settings with path:' . self::XML_PATH_CHECKOUT . 'mailbox');
        }

        if (!key_exists('active', $settings)) {
            $this->logger->critical('Can\'t get mailbox setting active');
        }

        $this->setMailboxActive($settings['active']);
        if ($this->isMailboxActive() === true) {
            $this->setShowMailboxWithOtherOptions($settings['other_options']);
            $this->setMaxWeight($settings['max_weight']);
        }

        return $this;
    }
}