<?php
/**
 * This class contain all methods to check the type of package
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


use Magento\Framework\App\ObjectManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Quote\Api\Data\EstimateAddressInterfaceFactory;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Sdk\src\Services\CheckApiKeyService;
use Psr\Log\LoggerInterface;

class Package extends Data implements PackageInterface
{
    const PACKAGE_TYPE_NORMAL = 1;
    const PACKAGE_TYPE_MAILBOX = 2;
    const PACKAGE_TYPE_LETTER = 3;

    /**
     * @var int
     */
    private $weight = 0;

    /**
     * @var int
     */
    private $max_mailbox_weight = 0;

    /**
     * @var bool
     */
    private $mailbox_active = false;

    /**
     * @var bool
     */
    private $all_products_fit = true;

    /**
     * @var bool
     */
    private $show_mailbox_with_other_options = true;

    /**
     * @var string
     */
    private $current_country = 'NL';

    /**
     * @var int
     */
    private $package_type = null;

    /**
     * @return int
     */
    public function getWeight()
    {
        return $this->weight;
    }

    /**
     * @param $weight
     */
    public function setWeight($weight)
    {
        $this->weight = (int)$weight;
    }

    /**
     * @param int $weight
     */
    public function addWeight($weight)
    {
        $this->weight += (int)$weight;
    }

    /**
     * @return bool
     */
    public function isMailboxActive()
    {
        return $this->mailbox_active;
    }

    /**
     * @param bool $mailbox_active
     */
    public function setMailboxActive($mailbox_active)
    {
        $this->mailbox_active = $mailbox_active;
    }

    /**
     * @return bool
     */
    public function isAllProductsFit()
    {
        return $this->all_products_fit;
    }

    /**
     * @param bool $all_products_fit
     */
    public function setAllProductsFit($all_products_fit)
    {
        if ($all_products_fit === false) {
            $this->all_products_fit = $all_products_fit;
        }
    }

    /**
     * @return bool
     */
    public function isShowMailboxWithOtherOptions()
    {
        return $this->show_mailbox_with_other_options;
    }

    /**
     * @param bool $show_mailbox_with_other_options
     * @return $this
     */
    public function setShowMailboxWithOtherOptions($show_mailbox_with_other_options)
    {
        $this->show_mailbox_with_other_options = $show_mailbox_with_other_options;

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxWeight()
    {
        return (int)$this->max_mailbox_weight;
    }

    /**
     * @param int $max_mailbox_weight
     */
    public function setMaxWeight($max_mailbox_weight)
    {
        $this->max_mailbox_weight = $max_mailbox_weight;
    }

    /**
     * package = 1, mailbox = 2, letter = 3
     *
     * @return int
     */
    public function getPackageType()
    {
        return $this->package_type;
    }

    /**
     * package = 1, mailbox = 2, letter = 3
     *
     * @param int $package_type
     */
    public function setPackageType($package_type)
    {
        $this->package_type = $package_type;
    }

    /**
     * @return string
     */
    public function getCurrentCountry()
    {
        return $this->current_country;
    }

    /**
     * @param string $current_country
     * @return Package
     */
    public function setCurrentCountry($current_country)
    {
        $this->current_country = $current_country;

        return $this;
    }
}
