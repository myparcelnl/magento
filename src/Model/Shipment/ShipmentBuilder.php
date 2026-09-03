<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use BadMethodCallException;
use InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment as MagentoShipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Carrier\Carrier as MagentoCarrier;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefShipmentPickup;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesPriceEuro;
use MyParcelNL\Sdk\Helper\SplitStreet;
use MyParcelNL\Sdk\Model\Shipment\Carrier as SdkCarrier;
use MyParcelNL\Sdk\Model\Shipment\Shipment;
use MyParcelNL\Sdk\Model\Shipment\ShipmentOptions as SdkShipmentOptions;

/**
 * Turns one Magento shipment track into a v11 Shipment, paired with its track and API key.
 *
 * It decides package type, delivery type, delivery date and weight; the rest of the option set
 * comes from ShipmentOptionsResolver::resolve(). A shipment that cannot be built fails on its own,
 * naming the order — never by substituting a value the merchant did not choose (DR-12, FR-000010).
 *
 * Generated-model traps: booleans must be a literal 1 or 0 (a PHP bool throws at serialization),
 * setLabelDescription() throws above 45 where the consignment truncated at 42, and
 * setDeliveryType() takes a literal int — a string enum name throws.
 */
class ShipmentBuilder
{
    /** AbstractConsignment::setLabelDescription() did Str::limit($x, 42); the v11 setter throws above 45. */
    private const MAX_LABEL_DESCRIPTION_LENGTH = 42;

    private ObjectManagerInterface    $objectManager;
    private Weight                    $weight;
    private JsonSerializer            $jsonSerializer;
    private DefaultOptions            $defaultOptions;
    private ShipmentApiProvider       $apiProvider;
    private ShipmentValidator         $validator;
    private CustomsDeclarationBuilder $customsBuilder;

    public function __construct(ObjectManagerInterface $objectManager, Order $order)
    {
        $this->objectManager  = $objectManager;
        $this->weight         = $objectManager->get(Weight::class);
        $this->jsonSerializer = $objectManager->get(JsonSerializer::class);
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

        $stored          = $this->storedDeliveryOptions($order, $options);
        $deliveryOptions = $this->parse($stored, $options);
        $carrier         = $deliveryOptions->getCarrier();

        $resolved = (new ShipmentOptionsResolver(
            $this->defaultOptions,
            $order,
            $deliveryOptions,
            $this->objectManager,
            $carrier,
            $options
        ))->resolve();

        $packageType = $this->packageType($deliveryOptions, $options, $resolved);
        $weight      = $this->weightInGrams($magentoTrack, $options, $packageType);

        $shipment = (new Shipment())
            ->setCarrier($this->carrierId($carrier))
            ->setReferenceIdentifier(self::referenceIdentifierFor((int) $magentoShipment->getEntityId(), $colloNumber))
            ->setRecipient($this->recipient($address, $carrier))
            ->setPhysicalProperties(['weight' => $weight])
            ->setOptions($this->options($deliveryOptions, $resolved, $packageType, $address));

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

    /**
     * The stored checkout data, with the carrier resolved and a pickup location dropped when the
     * carrier was overridden — a pickup location is carrier-specific, so an inherited one is no
     * longer reachable under a different carrier.
     */
    private function storedDeliveryOptions(Order $order, array $options): array
    {
        $stored          = $this->jsonSerializer->unserialize($order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? '[]') ?? [];
        $checkoutCarrier = $stored['carrier'] ?? null;
        $selected        = $this->carrierFromOptions($options) ?? $this->defaultOptions->getCarrierName();

        $stored['carrier'] = $selected;

        if ($checkoutCarrier && $selected !== $checkoutCarrier && ! empty($stored['isPickup'])) {
            unset($stored['pickupLocation']);
            $stored['isPickup']     = false;
            $stored['deliveryType'] = DeliveryType::STANDARD_NAME;
        }

        return $stored;
    }

    /**
     * A pickup that says pickup but carries no readable location is refused rather than degraded.
     * fromOrderFallback() would happily ship it as a home delivery, which is a different delivery
     * from the one the customer paid for — the substitution DR-12 exists to prevent.
     *
     * @throws \RuntimeException
     */
    private function parse(array $stored, array $options): DeliveryOptions
    {
        try {
            return DeliveryOptionsFactory::create($stored);
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException(
                sprintf('This order is a pickup but its pickup location cannot be read (%s)', $e->getMessage()),
                0,
                $e
            );
        } catch (BadMethodCallException $e) {
            return DeliveryOptions::fromOrderFallback($stored + $options);
        }
    }

    /** @throws \RuntimeException on a carrier no module name covers */
    private function carrierId(?string $carrier): int
    {
        $v2Name = null === $carrier ? null : Carrier::toV2Name($carrier);

        if (null === $v2Name) {
            throw new \RuntimeException(
                sprintf('carrier "%s" is not one this module knows', (string) $carrier)
            );
        }

        return SdkCarrier::toId($v2Name);
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

    /**
     * Every boolean is a literal 1 or 0: RefTypesIntBoolean is checked against [0, 1] with a strict
     * in_array during serialization, so true and false both throw at request time.
     */
    private function options(
        DeliveryOptions $deliveryOptions,
        ResolvedOptions $resolved,
        int             $packageType,
        $address
    ): SdkShipmentOptions
    {
        $options = (new SdkShipmentOptions())
            ->setPackageType($packageType)
            ->setDeliveryType($this->deliveryTypeId($deliveryOptions))
            ->setLabelDescription(mb_substr((string) $resolved->getLabelDescription(), 0, self::MAX_LABEL_DESCRIPTION_LENGTH))
            // Receipt code first: it blocks the options below it.
            ->setReceiptCode($this->flag($resolved->hasReceiptCode()))
            ->setOnlyRecipient($this->flag($resolved->hasOnlyRecipient()))
            ->setSignature($this->flag($resolved->hasSignature()))
            ->setCollect($this->flag($resolved->hasCollect()))
            ->setReturn($this->flag($deliveryOptions->isPickup() ? false : $resolved->hasReturn()))
            ->setSameDayDelivery($this->flag($resolved->hasSameDayDelivery()))
            ->setLargeFormat($this->flag($resolved->hasLargeFormat()))
            ->setAgeCheck($this->flag($resolved->hasAgeCheck()))
            ->setPriorityDelivery($this->flag($resolved->hasPriorityDelivery()));

        $deliveryDate = $this->deliveryDate($deliveryOptions, $packageType, $address);

        if (null !== $deliveryDate) {
            $options->setDeliveryDate($deliveryDate);
        }

        $insurance = (int) $resolved->getInsurance();

        if (0 < $insurance) {
            $options->setInsurance(
                (new RefTypesPriceEuro())
                    ->setCurrency(RefTypesPriceEuro::CURRENCY_EUR)
                    ->setAmount($insurance * 100)
            );
        }

        return $options;
    }

    /** @throws \RuntimeException on a stored delivery type that resolves to nothing sendable */
    private function deliveryTypeId(DeliveryOptions $deliveryOptions): int
    {
        $value = $deliveryOptions->deliveryTypeValue();

        if ($value->isAbsent()) {
            return DeliveryType::STANDARD;
        }

        try {
            return $value->toApiValue();
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function deliveryDate(DeliveryOptions $deliveryOptions, int $packageType, $address): ?string
    {
        if (PackageType::PACKAGE_SMALL === $packageType && CountryCode::CC_NL !== $address->getCountryId()) {
            return null;
        }

        return Dating::convertDeliveryDate($deliveryOptions->getDate());
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
     * Package type precedence, unchanged: an age check forces a package, then the explicit option,
     * then what the checkout stored, then the configured default.
     *
     * The two sources fail differently, and deliberately. An explicit option is the admin form's own
     * vocabulary, so an unmapped one falls back the way it always has. A *stored* type is the
     * customer's choice, so an unresolvable one fails the shipment instead of shipping as something
     * else (DR-12, FR-000010).
     *
     * @throws \RuntimeException on a stored type that resolves to no id
     */
    private function packageType(DeliveryOptions $deliveryOptions, array $options, ResolvedOptions $resolved): int
    {
        if ($resolved->hasAgeCheck()) {
            return PackageType::PACKAGE;
        }

        $explicit = $options['package_type'] ?? DefaultOptions::DEFAULT_OPTION_VALUE;

        if (DefaultOptions::DEFAULT_OPTION_VALUE !== $explicit) {
            return is_numeric($explicit)
                ? (int) $explicit
                : (PackageType::toIdOrNull((string) $explicit) ?? $this->defaultOptions->getPackageType());
        }

        $storedType = $deliveryOptions->packageTypeValue();

        if ($storedType->isAbsent()) {
            return $this->defaultOptions->getPackageType();
        }

        try {
            return $storedType->toApiValue();
        } catch (InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
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

    private function carrierFromOptions(array $options): ?string
    {
        if (empty($options['carrier'])) {
            return null;
        }

        return DefaultOptions::DEFAULT_OPTION_VALUE === $options['carrier']
            ? $this->defaultOptions->getCarrierName()
            : $options['carrier'];
    }

    /** @throws \RuntimeException naming the order, so one bad shipment does not fail the batch */
    private function assertValid(Shipment $shipment): void
    {
        $problems = $this->validator->problemsWith($shipment);

        if ($problems) {
            throw new \RuntimeException(implode('; ', $problems));
        }
    }

    private function flag(?bool $value): int
    {
        return $value ? 1 : 0;
    }
}
