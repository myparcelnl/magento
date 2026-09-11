<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment as MagentoShipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Carrier\Carrier as MagentoCarrier;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPickup;
use MyParcelNL\Sdk\Helper\SplitStreet;
use MyParcelNL\Sdk\Model\Shipment\Shipment;

/**
 * Turns one Magento shipment track into a v11 Shipment, paired with its track and API key.
 *
 * Carrier, package type and the option set come from OrderShipmentOptions, which the fulfilment
 * path shares. What is decided here is what only a label needs: the weight of these shipment items,
 * the recipient array and the pickup model. A shipment that cannot be built fails on its own,
 * naming the order — never by substituting a value the merchant did not choose (FR-000010).
 */
class ShipmentBuilder
{
    private ObjectManagerInterface    $objectManager;
    private Weight                    $weight;
    private DefaultOptions            $defaultOptions;
    private ShipmentApiProvider       $apiProvider;
    private ShipmentValidator         $validator;
    private CustomsDeclarationBuilder $customsBuilder;

    public function __construct(ObjectManagerInterface $objectManager, Order $order)
    {
        $this->objectManager  = $objectManager;
        $this->weight         = $objectManager->get(Weight::class);
        $this->apiProvider    = $objectManager->get(ShipmentApiProvider::class);
        $this->validator      = $objectManager->get(ShipmentValidator::class);
        $this->customsBuilder = $objectManager->get(CustomsDeclarationBuilder::class);
        $this->defaultOptions = new DefaultOptions($order);
    }

    /**
     * @throws LocalizedException when the order's store has no API key
     * @throws \RuntimeException  when the order cannot be exported as stored
     */
    public function build(Track $magentoTrack, array $options, int $colloNumber = 1): BuiltShipment
    {
        $magentoShipment = $magentoTrack->getShipment();

        if (null === $magentoShipment) {
            throw new \RuntimeException('This track has no Magento shipment, so there is nothing to export');
        }

        $order       = $magentoShipment->getOrder();
        $address     = $magentoShipment->getShippingAddress();
        $incrementId = (string) $order->getIncrementId();
        $apiKey      = $this->apiProvider->apiKeyForStore((int) $order->getStoreId());

        $shipmentOptions = new OrderShipmentOptions($this->objectManager, $order, $options, $this->defaultOptions);
        $deliveryOptions = $shipmentOptions->deliveryOptions();
        $packageType     = $shipmentOptions->packageType();
        $weight          = $this->weightInGrams($magentoTrack, $options, $packageType);

        $shipment = (new Shipment())
            ->setCarrier($shipmentOptions->carrierId())
            ->setReferenceIdentifier(self::referenceIdentifierFor((int) $magentoShipment->getEntityId(), $colloNumber))
            ->setRecipient($this->recipient($address, $shipmentOptions->carrierName()))
            ->setPhysicalProperties(['weight' => $weight])
            ->setOptions($shipmentOptions->shipmentOptions($address));

        if ($deliveryOptions->isPickup()) {
            $shipment->setPickup($this->pickup($deliveryOptions));
        }

        if (CountryCode::isRow((string) $address->getCountryId())) {
            $shipment->setCustomsDeclaration(
                $this->customsBuilder->build($magentoShipment, $weight, $incrementId)
            );
        }

        $this->assertValid($shipment);

        return new BuiltShipment($shipment, $magentoTrack, $apiKey, $incrementId);
    }

    /**
     * "<shipment entity id>-<collo number>", always suffixed.
     *
     * TR-000006 names the shipment entity id, which is unique per *shipment* but not per label: a
     * label_amount above one makes several Magento tracks for one shipment, and create() answers
     * [shipmentId => referenceIdentifier], so a shared reference would pair only one of them. The
     * suffix is uniform rather than added only from the second collo, so there is one format to read
     * and one prefix to match on. The API attaches no meaning to the value and nothing is stored
     * locally under it, so the change costs nothing.
     */
    public static function referenceIdentifierFor(int $shipmentEntityId, int $colloNumber): string
    {
        return $shipmentEntityId . '-' . $colloNumber;
    }

    /**
     * An unsaved Track carrying the fields the observer needs; it is added to the Magento shipment
     * only once the export has given it a barcode.
     */
    public function createTrackForShipment(MagentoShipment $magentoShipment): Track
    {
        /** @var Track $track */
        $track = $this->objectManager->create(Track::class);

        return $track
            ->setOrderId($magentoShipment->getOrderId())
            ->setShipment($magentoShipment)
            ->setCarrierCode(MagentoCarrier::CODE)
            ->setTitle(Config::MYPARCEL_TRACK_TITLE)
            ->setQty($magentoShipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY);
    }

    private function recipient($address, ?string $carrier): array
    {
        $street = SplitStreet::splitStreet(
            implode(' ', $address->getStreet() ?? []),
            Carrier::localCountryCodeFor($carrier),
            (string) $address->getCountryId()
        );

        $regionCode = $address->getRegionCode();

        return array_filter([
            'cc'            => (string) $address->getCountryId(),
            'postal_code'   => preg_replace('/\s+/', '', (string) $address->getPostcode()),
            'city'          => (string) $address->getCity(),
            'street'        => $street->getStreet(),
            'number'        => (string) $street->getNumber(),
            'number_suffix' => (string) $street->getNumberSuffix(),
            'box_number'    => (string) $street->getBoxNumber(),
            'person'        => (string) $address->getName(),
            'company'       => (string) $address->getCompany(),
            'email'         => (string) $address->getEmail(),
            'phone'         => (string) $address->getTelephone(),
            'state'         => $regionCode && 2 === strlen($regionCode) ? $regionCode : null,
        ], static fn($value): bool => null !== $value && '' !== $value);
    }

    private function pickup(DeliveryOptions $deliveryOptions): RefShipmentPickup
    {
        $location = $deliveryOptions->getPickupLocation();

        if (null === $location) {
            throw new \RuntimeException('this order is a pickup but carries no pickup location');
        }

        $pickup = (new RefShipmentPickup())
            ->setPostalCode($location->getPostalCode())
            ->setStreet($location->getStreet())
            ->setCity($location->getCity())
            ->setNumber($location->getNumber())
            ->setCc($location->getCountry())
            ->setLocationName($location->getLocationName())
            ->setLocationCode($location->getLocationCode());

        if ($location->getRetailNetworkId()) {
            $pickup->setRetailNetworkId($location->getRetailNetworkId());
        }

        return $pickup;
    }

    /**
     * A digital stamp weight is always grams, whatever the weight unit setting says. A preset of
     * zero — nothing posted and no default configured — falls through to the item weights, as the
     * consignment path did; the API refuses a zero weight.
     */
    private function weightInGrams(Track $magentoTrack, array $options, int $packageType): int
    {
        if (PackageType::DIGITAL_STAMP === $packageType) {
            $preset = (int) (($options['digital_stamp_weight'] ?? null) ?: $this->defaultOptions->getDigitalStampDefaultWeight());

            if (0 < $preset) {
                return $preset;
            }
        }

        $total = 0.0;

        foreach ($magentoTrack->getShipment()->getItems() as $item) {
            $total += (float) $item['weight'] * (float) $item['qty'];
        }

        return $this->weight->convertToGrams($total) + $this->weight->getEmptyPackageWeightInGrams($packageType);
    }

    /** @throws \RuntimeException naming the order, so one bad shipment does not fail the batch */
    private function assertValid(Shipment $shipment): void
    {
        $problems = $this->validator->problemsWith($shipment);

        if ($problems) {
            throw new \RuntimeException(implode('; ', $problems));
        }
    }
}
