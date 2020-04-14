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
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
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
    const PACKAGE_TYPE_NORMAL        = 1;
    const PACKAGE_TYPE_MAILBOX       = 2;
    const PACKAGE_TYPE_LETTER        = 3;
    const PACKAGE_TYPE_DIGITAL_STAMP = 4;

    /**
     * @var int
     */
    private $weight = 0;

    /**
     * @var int
     */
    private $max_weight = 0;

    /**
     * @var bool
     */
    private $mailbox_active = false;

    /**
     * @var bool
     */
    private $digital_stamp_active = false;

    /**
     * @var bool
     */
    private $all_products_fit_in_mailbox = false;

    /**
     * @var bool
     */
    private $all_products_fit_in_digital_stamp = false;

    /**
     * @var bool
     */
    private $all_products_fit = true;

    /**
     * @var string
     */
    private $current_country = 'NL';

    /**
     * @var int
     */
    private $package_type = null;

    /**
     * @var int
     */
    private $mailbox_procent = 0;

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
        $this->weight = (int) $weight;
    }

    /**
     * @param int $weight
     */
    public function addWeight($weight)
    {
        $this->weight += (int) $weight;
    }

    /**
     * @return int
     */
    public function getMaxWeight()
    {
        return (int) $this->max_weight;
    }

    /**
     * @param int $max_weight
     */
    public function setMaxWeight($max_weight)
    {
        $this->max_weight = $max_weight;
    }


    /**
     * @return bool
     */
    public function isAllProductsFitInMailbox()
    {
        return $this->all_products_fit_in_mailbox;
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
     * @param $procent
     */
    public function setMailboxProcent($procent)
    {
        $this->mailbox_procent = $procent;
    }
    /**
     * @return bool
     */
    public function getMailboxProcent()
    {
        return $this->mailbox_procent;
    }

    /**
     * @return bool
     */
    public function isAllProductsFitInDigitalStamp()
    {
        return $this->all_products_fit_in_digital_stamp;
    }

    /**
     * @return bool
     */
    public function isDigitalStampActive()
    {
        return $this->digital_stamp_active;
    }

    /**
     * @param bool $digital_stamp_active
     */
    public function setDigitalStampActive($digital_stamp_active)
    {
        $this->digital_stamp_active = $digital_stamp_active;
    }

    /**
     * @param bool $all_products_fit_in_mailbox
     * @param null $package_type
     */
    public function setAllProductsFitInPackageType($all_products_fit_in_mailbox, $package_type = null)
    {
        if ($all_products_fit_in_mailbox === true && $package_type === 'mailbox') {
            $this->all_products_fit_in_mailbox = $all_products_fit_in_mailbox;
        }

        if ($all_products_fit_in_mailbox === true && $package_type === 'digital_stamp') {
            $this->all_products_fit_in_digital_stamp = $all_products_fit_in_mailbox;
        }
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
     * package = 1
     *
     * @return int
     */
    public function getPackageType()
    {
        return $this->package_type;
    }

    /**
     * package = 1
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
     *
     * @return Package
     */
    public function setCurrentCountry($current_country)
    {
        $this->current_country = $current_country;

        return $this;
    }
}
