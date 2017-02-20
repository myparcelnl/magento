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
     * Recipient email config path
     */
    const CONFIG_PATH_BASE_API_KEY = 'basic_settings/print/paper_type';

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
    public function createTrackTraceFromShipment(Order\Shipment $shipment)
    {
        $this->mageTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $this->mageTrack
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(self::MYPARCEL_CARRIER_CODE)
            ->setTitle(self::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber('concept')
            ->save();

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
    public function convertDataFromMagentoToApi($magentoTrack, $options = [])
    {
        $address = $magentoTrack->getShipment()->getShippingAddress();
        $this
            ->setApiKey($this->helper->getGeneralConfig('api/key'))
            ->setReferenceId($magentoTrack->getEntityId())
            ->setMyParcelConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
            ->setCountry($address->getCountryId())
            ->setCompany($address->getCompany())
            ->setPerson($address->getName())
            ->setFullStreet($address->getData('street'))
            ->setPostalCode($address->getPostcode())
            ->setCity($address->getCity())
            ->setPhone($address->getTelephone())
            ->setEmail($address->getEmail())
            ->setLabelDescription($magentoTrack->getShipment()->getOrder()->getIncrementId())
            ->setPackageType((int)$options['package_type'] == null ? 1 : (int)$options['package_type'])
            ->setOnlyRecipient($this->getOption('only_recipient'))
            ->setSignature($this->getOption('signature'))
            ->setReturn($this->getOption('return'))
            ->setLargeFormat($this->getOption('large_format'))
            ->setInsurance($options['insurance'] !== null ?: self::$defaultOptions->getDefaultInsurance());

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
     * @param $option
     *
     * @return bool
     */
    private function getOption($option)
    {
        if ($option === null) {
            return (bool)self::$defaultOptions->getDefault($option);
        } else {
            return (bool)$option;
        }
    }
}
