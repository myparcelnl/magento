<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment as MagentoShipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Carrier\Carrier as MagentoCarrier;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\TrackTrace\TrackNumber;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPickup;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentPostShipmentsRequestV11DataShipmentsInnerGeneralSettings as GeneralSettings;
use MyParcelNL\Sdk\Helper\SplitStreet;
use MyParcelNL\Sdk\Model\Shipment\Shipment;
use MyParcelNL\Sdk\Services\CountryService;

/**
 * Turns one Magento shipment track into a v11 Shipment, paired with its track and API key.
 *
 * Carrier, package type and the option set come from OrderExportOptions, which the Order v1
 * path shares. What is decided here is what only a label needs: the weight of these shipment items,
 * the recipient array and the pickup model. A shipment that cannot be built fails on its own, and
 * the caller names the order. An unknown stored carrier fails the shipment; it is never replaced.
 */
class ShipmentBuilder
{
    private ObjectManagerInterface    $objectManager;
    private Weight                    $weight;
    private DefaultOptions            $defaultOptions;
    private ShipmentApiProvider       $apiProvider;
    private ShipmentValidator         $validator;
    private CustomsDeclarationBuilder $customsBuilder;
    private StoredAccount             $storedAccount;
    private RecipientFields           $recipientFields;
    private CountryService            $countryService;

    /** @var array<int,OrderExportOptions> by shipment; a multicollo order asks per collo */
    private array $orderOptions = [];

    public function __construct(ObjectManagerInterface $objectManager, Order $order)
    {
        $this->objectManager  = $objectManager;
        $this->weight         = $objectManager->get(Weight::class);
        $this->apiProvider    = $objectManager->get(ShipmentApiProvider::class);
        $this->validator      = $objectManager->get(ShipmentValidator::class);
        $this->customsBuilder = $objectManager->get(CustomsDeclarationBuilder::class);
        $this->recipientFields = $objectManager->get(RecipientFields::class);
        $this->storedAccount  = $objectManager->get(StoredAccount::class);
        $this->countryService = $objectManager->get(CountryService::class);
        $this->defaultOptions = new DefaultOptions($order);
    }

    /**
     * Resolved once per shipment, not once per collo: each instance re-reads the order's delivery
     * options and, for a label description that names a product, its items.
     */
    private function orderOptionsFor(MagentoShipment $magentoShipment): OrderExportOptions
    {
        $key = spl_object_id($magentoShipment);

        return $this->orderOptions[$key] ?? $this->orderOptions[$key] = new OrderExportOptions(
            $this->objectManager,
            $magentoShipment->getOrder(),
            $this->defaultOptions,
            $magentoShipment
        );
    }

    /**
     * @throws LocalizedException when the order's store has no API key
     * @throws \RuntimeException  when the order cannot be exported as stored
     */
    public function build(Track $magentoTrack, int $colloNumber = 1): BuiltShipment
    {
        $magentoShipment = $magentoTrack->getShipment();

        $order       = $magentoShipment->getOrder();
        $address     = $magentoShipment->getShippingAddress();
        $incrementId = (string) $order->getIncrementId();
        $apiKey      = $this->apiProvider->apiKeyForStore((int) $order->getStoreId());

        // the observer builds on save_before, when the shipment has no id yet
        $shipmentId      = $magentoShipment->getEntityId() ? (int) $magentoShipment->getEntityId() : null;
        $orderOptions    = $this->orderOptionsFor($magentoShipment);
        $deliveryOptions = $orderOptions->deliveryOptionsToShip();
        $packageType     = $orderOptions->packageTypeToShip();
        $weight          = $this->weightInGrams($magentoTrack, $packageType);

        $shipment = (new Shipment())
            ->setCarrier($orderOptions->carrierIdToShip())
            ->setReferenceIdentifier(self::referenceIdentifierFor((string) ($shipmentId ?? $incrementId), $colloNumber))
            ->setRecipient($this->recipient($address, $this->storedAccount->homeCountryForApiKey($apiKey)))
            ->setPhysicalProperties(self::physicalProperties($weight, $deliveryOptions))
            ->setOptions($orderOptions->sdkShipmentOptions())
            ->setGeneralSettings((new GeneralSettings())->setSaveRecipientAddress(0));

        if ($deliveryOptions->isPickup()) {
            $shipment->setPickup($this->pickup($deliveryOptions));
        }

        if ($this->countryService->isRow((string) $address->getCountryId())) {
            $shipment->setCustomsDeclaration(
                $this->customsBuilder->build($magentoShipment, $weight, $incrementId)
            );
        }

        $this->assertValid($shipment);

        return new BuiltShipment($shipment, $magentoTrack, $apiKey, $incrementId);
    }

    /** The weight in grams, and the length, width and height in cm a merchant saved on the order. */
    private static function physicalProperties(int $weight, DeliveryOptions $deliveryOptions): array
    {
        return ['weight' => $weight] + $deliveryOptions->getDimensions();
    }

    /**
     * Get "<shipment entity id>-<collo number>", or "<order increment id>-<collo number>" for a shipment
     * that is not saved yet.
     *
     * A label_amount above one makes several tracks for one shipment, and create() pairs a response
     * by this value, so each track needs its own.
     */
    private static function referenceIdentifierFor(string $shipmentKey, int $colloNumber): string
    {
        return "$shipmentKey-$colloNumber";
    }

    /**
     * What a MyParcel track row looks like before it has a barcode. Static because the grid path
     * builds one without an order in hand; MagentoCollection::setNewMagentoTrack() saves it.
     *
     * Sets parent_id itself: TrackRepository::save() checks it before the resource model would.
     */
    public static function newTrackFor(ObjectManagerInterface $objectManager, MagentoShipment $magentoShipment): Track
    {
        /** @var Track $track */
        $track = $objectManager->create(Track::class);

        return $track
            ->setOrderId($magentoShipment->getOrderId())
            ->setParentId($magentoShipment->getId())
            ->setShipment($magentoShipment)
            ->setCarrierCode(MagentoCarrier::CODE)
            ->setTitle(Config::MYPARCEL_TRACK_TITLE)
            ->setQty($magentoShipment->getTotalQty())
            ->setTrackNumber(TrackNumber::EMPTY);
    }

    private function recipient($address, string $localCountry): array
    {
        $street = SplitStreet::splitStreet(
            implode(' ', $address->getStreet() ?? []),
            $localCountry,
            (string) $address->getCountryId()
        );

        return array_filter([
            'cc'            => (string) $address->getCountryId(),
            'postal_code'   => $this->recipientFields->postalCode($address),
            'city'          => (string) $address->getCity(),
            'street'        => $street->getStreet(),
            'number'        => (string) $street->getNumber(),
            'number_suffix' => (string) $street->getNumberSuffix(),
            'box_number'    => (string) $street->getBoxNumber(),
            'person'        => $this->recipientFields->person($address),
            'company'       => (string) $address->getCompany(),
            'email'         => (string) $address->getEmail(),
            'phone'         => (string) $address->getTelephone(),
            'state'         => $this->recipientFields->state($address),
        ], static fn($value): bool => null !== $value && '' !== $value);
    }

    private function pickup(DeliveryOptions $deliveryOptions): RefShipmentPickup
    {
        $location = $deliveryOptions->getPickupLocation();

        if (null === $location) {
            throw new \RuntimeException((string) __('this order is a pickup but carries no pickup location'));
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
     * Get the weight in grams. A digital stamp weight is always grams, whatever the unit setting.
     * A preset of zero (nothing saved, no default) falls through to the item weights: the API
     * refuses a zero weight.
     */
    private function weightInGrams(Track $magentoTrack, int $packageType): int
    {
        if (PackageType::DIGITAL_STAMP === $packageType) {
            $preset = $this->defaultOptions->getDigitalStampDefaultWeight();

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

    /** @throws \RuntimeException with the problems, so one bad shipment does not fail the batch */
    private function assertValid(Shipment $shipment): void
    {
        $problems = $this->validator->problemsWith($shipment);

        if ($problems) {
            throw new \RuntimeException(implode('; ', $problems));
        }
    }
}
