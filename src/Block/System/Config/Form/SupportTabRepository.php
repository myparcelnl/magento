<?php
/**
 * Show MyParcel options in config support tab
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Service\Config;

class SupportTabRepository extends \Magento\Backend\Block\Widget
{
    public function __construct(
        Context $context,
        Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
    }
    /**
     * Get the url of the stylesheet
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCssUrl(): string
    {
        return $this->_assetRepo->createAsset('MyParcelNL_Magento::css/config/support_tab/style.css')->getUrl();
    }

    /**
     * Get the version number of the installed module
     *
     * @return string
     */
    public function getModuleVersion(): string
    {
        return $this->config->getVersion();
    }
}
