<?php
/**
 * An object with the track and trace data
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;
use MyParcelNL\Sdk\src\Model\Repository\MyParcelConsignmentRepository;
use MyParcelNL\Magento\Helper\Data;

class MyParcelTrackTrace extends MyParcelConsignmentRepository
{
    /**
     * Track title showing in Magento
     */
    const MYPARCEL_TRACK_TITLE = 'MyParcel';
    const MYPARCEL_CARRIER_CODE = 'myparcelnl';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \MyParcelNL\Magento\Model\Source\DefaultOptions
     */
    private static $defaultOptions;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Order\Shipment\Track
     */
    public $mageTrack;

    /**
     * MyParcelTrackTrace constructor.
     *
     * @param ObjectManagerInterface     $objectManager
     * @param Data                       $helper
     * @param \Magento\Sales\Model\Order $order
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Data $helper,
        Order $order
    ) {
        $this->objectManager = $objectManager;
        $this->helper = $helper;
        $this->messageManager = $this->objectManager->create('Magento\Framework\Message\ManagerInterface');;
        self::$defaultOptions = new DefaultOptions(
            $order,
            $this->helper
        );
    }

    /**
     * Create Magento Track from Magento shipment
     *
     * @param Order\Shipment $shipment
     *
     * @return $this
     */
    public function createTrackTraceFromShipment(Order\Shipment &$shipment)
    {
        $this->mageTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $this->mageTrack
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(self::MYPARCEL_CARRIER_CODE)
            ->setTitle(self::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber('concept');


        return $this;
    }

    /**
     * Set all data to MyParcel object
     *
     * @param Order\Shipment\Track $magentoTrack
     * @param array                $options
     *
     * @return $this
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function convertDataFromMagentoToApi($magentoTrack, $options)
    {
        $address = $magentoTrack->getShipment()->getShippingAddress();
        $checkoutData = $magentoTrack->getShipment()->getOrder()->getData('delivery_options');
        $deliveryType = $this->getDeliveryTypeFromCheckout($checkoutData);
        if ($options['package_type'] === 'default') {
            $packageType = self::$defaultOptions->getPackageType();
        } else {
            $packageType = (int)$options['package_type'] ?: 1;
        }

        if ($address->getCountryId() != 'NL' && (int)$options['package_type'] == 2) {
            $options['package_type'] = 1;
        }

        $this
            ->setApiKey(
                $this->helper->getGeneralConfig('api/key',
                    $magentoTrack->getShipment()->getOrder()->getStoreId()
                ))
            ->setReferenceId($magentoTrack->getEntityId())
            ->setMyParcelConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
            ->setCountry($address->getCountryId())
            ->setCompany($address->getCompany())
            ->setPerson($address->getName());

        try {
            $this->setFullStreet($address->getData('street'));
        } catch (\Exception $e) {
            $errorHuman = 'An error has occurred while validating the address: ' . $address->getData('street') . '. Check number and number suffix.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman . '-' . $e);
        }

        if ($address->getPostcode() == null && $address->getCountryId() == 'NL') {
            $errorHuman = 'An error has occurred while validating the order number ' . $magentoTrack->getOrderId(). '. Postcode is required.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman);
        }

        $this
            ->setPostalCode($address->getPostcode())
            ->setCity($address->getCity())
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($magentoTrack->getShipment()->getOrder()->getIncrementId())
            ->setDeliveryDateFromCheckout($checkoutData)
            ->setDeliveryType($deliveryType)
            ->setPickupAddressFromCheckout($checkoutData)
            ->setPackageType($packageType)
            ->setOnlyRecipient($this->getValueOfOption($options, 'only_recipient'))
            ->setSignature($this->getValueOfOption($options, 'signature'))
            ->setReturn($this->getValueOfOption($options, 'return'))
            ->setLargeFormat($this->getValueOfOption($options, 'large_format'))
            ->setInsurance($options['insurance'] !== null ? $options['insurance'] : self::$defaultOptions->getDefaultInsurance())
            ->convertDataForCdCountry($magentoTrack);

        return $this;
    }

    /**
     * Override to check if key isset
     *
     * @param string $apiKey
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function setApiKey($apiKey)
    {
        if ($apiKey == null) {
            throw new LocalizedException(__('API key is not known. Go to the settings in the back office of MyParcel to create an API key. Fill the API key in the settings.'));
        }
        parent::setApiKey($apiKey);

        return $this;
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     * @return $this
     *
     * @todo Add setting to global setting and/or category (like magento 1)
     * @todo Get Classification from setting and/or category
     * @todo Get country of manufacture (get attribute from product)
     * @todo Find out why the weight does not come on the label
     * @todo Find out why the price does not come on the label
     */
    private function convertDataForCdCountry($magentoTrack)
    {
        if (!$this->isCdCountry()) {
            return $this;
        }

        if ($magentoTrack->getShipment()->getData('items') != null) {
            $products = $magentoTrack->getShipment()->getData('items');

            foreach ($products as $product) {
                $myParcelProduct = (new MyParcelCustomsItem())
                    ->setDescription($product->getName())
                    ->setAmount($product->getQty())
                    ->setWeight($product->getWeight() ?: 1)
                    ->setItemValue($product->getPrice())
                    ->setClassification('0000')
                    ->setCountry('NL');

                $this->addItem($myParcelProduct);
            }
        }

        $products = $this->getItemsCollectionByShipmentId($magentoTrack->getShipment()->getId());

        foreach ($products as $product) {
            $myParcelProduct = (new MyParcelCustomsItem())
                ->setDescription($product['name'])
                ->setAmount($product['qty'])
                ->setWeight($product['weight'] ?: 1)
                ->setItemValue($product['price'])
                ->setClassification('0000')
                ->setCountry('NL');

            $this->addItem($myParcelProduct);
        }

        return $this;
    }

    /**
     * Get default value if option === null
     *
     * @param $options[]
     * @param $optionKey
     *
     * @return bool
     * @internal param $option
     *
     */
    private function getValueOfOption($options, $optionKey)
    {
        if ($options[$optionKey] === null) {
            return (bool)self::$defaultOptions->getDefault($optionKey);
        } else {
            return (bool)$options[$optionKey];
        }
    }

    /**
     * @param $shipmentId
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private function getItemsCollectionByShipmentId($shipmentId)
    {
        $connection = $this->objectManager->create('\Magento\Framework\App\ResourceConnection');
        $conn = $connection->getConnection();
        $select = $conn->select()
            ->from(
                ['main_table' => 'sales_shipment_item']
            )
            ->where('main_table.parent_id=?', $shipmentId);
        $items = $conn->fetchAll($select);

        return $items;
    }
}
