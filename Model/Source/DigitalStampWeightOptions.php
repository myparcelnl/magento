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
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * @api
 * @since 100.0.2
 */
class DigitalStampWeightOptions implements OptionSourceInterface
{
    static private $configService;

    /**
     * @param $configService Config
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
    public function getDefault($option): bool
    {
        throw new \Exception('again, not really a default');
        $settings = self::$configService->getCarrierConfig(CarrierPostNL::NAME, 'options');

        return (bool) $settings[$option . '_active'];
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => __('No standard weight')],
            ['value' => 20, 'label' => __('0 - 20 gram')],
            ['value' => 50, 'label' => __('20 - 50 gram')],
            ['value' => 200, 'label' => __('50 - 350 gram')],
            ['value' => 2000, 'label' => __('350 - 2000 gram')]
        ];
    }
}
