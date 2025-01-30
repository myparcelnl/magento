<?php
/**
 * Get only the "No" option for in the MyParcel system settings
 * This option is used with settings that are not possible because an parent option is turned off.
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Richard Perdaan <support@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * @api
 * @since 100.0.2
 */
class AgeCheckYes implements OptionSourceInterface
{
    static private Config $configService;

    /**
     * Insurance constructor.
     *
     * @param $order Order
     * @param $helper Data
     */
    public function __construct(Config $configService)
    {
        self::$configService = $configService;
    }

    /**
     * @param $option
     *
     * @return bool
     */
    public function hasDefault($option): bool
    {
        throw new \Exception('this is not really a default option');
        $settings = self::$configService->getCarrierConfig(CarrierPostNL::NAME, 'default_options');

        return (bool) $settings[$option . '_active'];
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        if ($this->hasDefault('age_check')) {
            return [['value' => 1, 'label' => __('Yes')]];
        }

        return [['value' => 1, 'label' => __('Yes')], ['value' => 0, 'label' => __('No')]];
    }
}
