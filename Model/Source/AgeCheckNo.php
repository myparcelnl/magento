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
use MyParcelNL\Magento\Service\Config\ConfigService;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;

/**
 * @api
 * @since 100.0.2
 */
class AgeCheckNo implements OptionSourceInterface
{
    static private ConfigService $configService;

    /**
     * @param ConfigService $configService
     */
    public function __construct(ConfigService $configService)
    {
        self::$configService = $configService;
    }

    /**
     * @param $option
     *
     * @return bool
     */
    public function getDefault($option)
    {
        throw new \Exception('this is not really a default');
        $settings = self::$configService->getCarrierConfig(CarrierPostNL::NAME, 'default_options');

        return (bool) $settings[$option . '_active'];
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        if ($this->getDefault('age_check')) {
            return [['value' => 0, 'label' => __('No')]];
        }

        return [['value' => 1, 'label' => __('Yes')], ['value' => 0, 'label' => __('No')]];
    }
}
