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


use Magento\Framework\Module\ModuleListInterface;
use MyParcelNL\Magento\Helper\Data;
use Magento\Framework\App\Helper\Context;
use Psr\Log\LoggerInterface;

class Package extends Data
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
     * Mailbox constructor
     *
     * @param Context $context
     * @param ModuleListInterface $moduleList
     * @param LoggerInterface $logger
     */
    public function __construct(Context $context, ModuleListInterface $moduleList, LoggerInterface $logger)
    {
        parent::__construct($context, $moduleList, $logger);

        $this->setMailboxSettings();
    }

    /**
     * @return int
     */
    public function getWeight(): int
    {
        return $this->weight;
    }

    /**
     * @param $weight
     */
    public function setWeight($weight)
    {
        $this->weight = $weight;
    }

    /**
     * @param int $weight
     */
    public function addWeight(int $weight)
    {
        $this->weight += $weight;
    }

    /**
     * @return bool
     */
    public function isMailboxActive(): bool
    {
        return $this->mailbox_active;
    }

    /**
     * @param bool $mailbox_active
     */
    public function setMailboxActive(bool $mailbox_active)
    {
        $this->mailbox_active = $mailbox_active;
    }

    /**
     * @return bool
     */
    public function isAllProductsFit(): bool
    {
        return $this->all_products_fit;
    }

    /**
     * @param bool $all_products_fit
     */
    public function setAllProductsFit(bool $all_products_fit)
    {
        if ($all_products_fit === true) {
            $this->all_products_fit = $all_products_fit;
        }
    }

    /**
     * @return bool
     */
    public function isShowMailboxWithOtherOptions(): bool
    {
        return $this->show_mailbox_with_other_options;
    }

    /**
     * @param bool $show_mailbox_with_other_options
     * @return $this
     */
    public function setShowMailboxWithOtherOptions(bool $show_mailbox_with_other_options)
    {
        $this->show_mailbox_with_other_options = $show_mailbox_with_other_options;

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxWeight(): int
    {
        return $this->max_mailbox_weight;
    }

    /**
     * @param int $max_mailbox_weight
     */
    public function setMaxWeight(int $max_mailbox_weight)
    {
        $this->max_mailbox_weight = $max_mailbox_weight;
    }

    /**
     * package = 1, mailbox = 2, letter = 3
     *
     * @return int
     */
    public function getPackageType(): int
    {
        return $this->package_type;
    }

    /**
     * package = 1, mailbox = 2, letter = 3
     *
     * @param int $package_type
     */
    public function setPackageType(int $package_type)
    {
        $this->package_type = $package_type;
    }

    /**
     * @return string
     */
    public function getCurrentCountry(): string
    {
        return $this->current_country;
    }

    /**
     * @param string $current_country
     * @return Package
     */
    public function setCurrentCountry(string $current_country): Package
    {
        $this->current_country = $current_country;

        return $this;
    }

    /**
     * Init all mailbox settings
     */
    private function setMailboxSettings()
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
    }
}