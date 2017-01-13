<?php
/**
 * The class to provide functions for new_shipment.phtml
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




use Magento\Sales\Block\Adminhtml\Items\AbstractItems;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use Magento\Framework\App\ObjectManager;

class NewShipment extends AbstractItems
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var Order
     */
    protected $modelOrder;

    private $defaultOptions;

    /**
     * @param \Magento\Backend\Block\Template\Context                   $context
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface      $stockRegistry
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\Framework\Registry                               $registry
     * @param array                                                     $data
     *
     * @throws \Exception
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        $this->_objectManager = ObjectManager::getInstance();
        $this->modelOrder = $this->_objectManager->create('Magento\Sales\Model\Order');

        $orderId = $context->getRequest()->getParam('order_id', null);
        if ($orderId == null) {
            throw new \Exception('No order id found');
        }

        $this->defaultOptions = new DefaultOptions(
            $this->modelOrder->load($orderId),
            $this->_objectManager->get('\MyParcelNL\Magento\Helper\Data')
        );

        parent::__construct($context, $stockRegistry, $stockConfiguration, $registry);
    }

    /**
     * @param $option 'only_recipient'|'signature'|'return'|'large_format'
     *
     * @return bool
     */
    public function getDefaultOption($option)
    {
        return $this->defaultOptions
            ->getDefault($option);
    }

    /**
     * Get default value of insurance based on order grand total
     *
     * @return int
     */
    public function getDefaultInsurance()
    {
        return $this->defaultOptions
            ->getDefaultInsurance();
    }
}