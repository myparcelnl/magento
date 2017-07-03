<?php
/**
 * ${CARET}
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

namespace MyParcelNL\Magento\Model\Sales;


interface PackageInterface
{
    /**
     * @return int
     */
    public function getWeight();
    /**
     * @param $weight
     */
    public function setWeight($weight);
    /**
     * @param int $weight
     */
    public function addWeight($weight);
    /**
     * @return bool
     */
    public function isMailboxActive();
    /**
     * @param bool $mailbox_active
     */
    public function setMailboxActive($mailbox_active);
    /**
     * @return bool
     */
    public function isAllProductsFit();
    /**
     * @param bool $all_products_fit
     */
    public function setAllProductsFit($all_products_fit);
    /**
     * @return bool
     */
    public function isShowMailboxWithOtherOptions();
    /**
     * @param bool $show_mailbox_with_other_options
     * @return $this
     */
    public function setShowMailboxWithOtherOptions($show_mailbox_with_other_options);
    /**
     * @return int
     */
    public function getMaxWeight();
    /**
     * @param int $max_mailbox_weight
     */
    public function setMaxWeight($max_mailbox_weight);
    /**
     * package = 1, mailbox = 2, letter = 3
     *
     * @return int
     */
    public function getPackageType();
    /**
     * package = 1, mailbox = 2, letter = 3
     *
     * @param int $package_type
     */
    public function setPackageType($package_type);
    /**
     * @return string
     */
    public function getCurrentCountry();
    /**
     * @param string $current_country
     * @return Package
     */
    public function setCurrentCountry($current_country);
}