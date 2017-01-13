<?php
/**
 * Short_description
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Block\Sales;




use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;

class View extends Template
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    protected $_helper;

    /**
     * @param Context $context
     * @param array $data
     */
    public function __construct(Context $context, array $data = [])
    {
        $this->_objectManager = ObjectManager::getInstance();
        $this->_helper = $this->_objectManager->get('\MyParcelNL\Magento\Helper\Data');
        parent::__construct($context, $data);
    }
    public function getAjaxUrl()
    {
        return $this->_urlBuilder->getUrl('myparcelnl/order/MassTrackTraceLabel');
    }

    public function getSettings()
    {
        $settings = $this->_helper->getStandardConfig('print');
        return json_encode($settings);
    }
}