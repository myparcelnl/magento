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

        if ($address->getCountryId() != 'NL' && (int)$options['package_type'] == 2) {
            $options['package_type'] = 1;
        }

        $this
            ->setApiKey($this->helper->getGeneralConfig('api/key', $magentoTrack->getShipment()->getOrder()->getStoreId()))
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

        $this
            ->setPostalCode($address->getPostcode())
            ->setCity($address->getCity())
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($magentoTrack->getShipment()->getOrder()->getIncrementId())
            ->setPackageType((int)$options['package_type'] == null ? 1 : (int)$options['package_type'])
            ->setOnlyRecipient($this->getValueOfOption($options, 'only_recipient'))
            ->setSignature($this->getValueOfOption($options, 'signature'))
            ->setReturn($this->getValueOfOption($options, 'return'))
            ->setLargeFormat($this->getValueOfOption($options, 'large_format'))
            ->setInsurance($options['insurance'] !== null ? $options['insurance'] : self::$defaultOptions->getDefaultInsurance());

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
}
