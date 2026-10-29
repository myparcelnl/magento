<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ShipmentOptions;

use BadMethodCallException;
use InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Adapter\DeliveryOptions\PickupLocation;
use MyParcelNL\Magento\Model\Sales\Repository\DeliveryRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\OrderGridColumns;
use RuntimeException;

/**
 * Writes a merchant's shipment option changes into each order's stored delivery options.
 *
 * Every order is checked against its account's capabilities before any order is written, so a
 * refused change leaves the whole selection as it was.
 */
class OrderOptionsWriter
{
    private CapabilitiesRepository $capabilitiesRepository;
    private ShipmentApiProvider    $apiProvider;
    private OrderGridColumns       $gridColumns;
    private Dating                 $dating;
    private PickupLocations        $pickupLocations;

    public function __construct(
        CapabilitiesRepository $capabilitiesRepository,
        ShipmentApiProvider    $apiProvider,
        OrderGridColumns       $gridColumns,
        Dating                 $dating,
        PickupLocations        $pickupLocations
    ) {
        $this->capabilitiesRepository = $capabilitiesRepository;
        $this->apiProvider            = $apiProvider;
        $this->gridColumns            = $gridColumns;
        $this->dating                 = $dating;
        $this->pickupLocations        = $pickupLocations;
    }

    /**
     * Writes only the changed columns, not the whole order, and sets them on the order objects too.
     *
     * @param Order[] $orders
     *
     * @throws LocalizedException when the orders span accounts or a change is not offered
     */
    public function write(array $orders, OptionChanges $changes): void
    {
        foreach ($this->validated($orders, $changes) as [$order, $columns]) {
            $this->gridColumns->update((int) $order->getId(), $columns);

            foreach ($columns as $column => $value) {
                $order->setData($column, $value);
            }
        }
    }

    /** @param Order[] $orders */
    public function assertOneAccount(array $orders): void
    {
        $keys = [];

        foreach ($orders as $order) {
            $keys[(string) $this->apiProvider->apiKeyForStoreOrNull((int) $order->getStoreId())] = true;
        }

        if (count($keys) > 1) {
            throw new LocalizedException(__('Select orders of one MyParcel account. The selected orders belong to more than one.'));
        }
    }

    /**
     * Every order with its new column values, once all of them passed: a refusal writes nothing.
     *
     * @param Order[] $orders
     *
     * @return array<int,array{0:Order,1:array<string,mixed>}>
     * @throws LocalizedException
     */
    private function validated(array $orders, OptionChanges $changes): array
    {
        if ([] === $orders || $changes->isEmpty()) {
            return [];
        }

        $this->assertOneAccount($orders);

        $lookup    = new ShapeLookup($this->capabilitiesRepository);
        $validated = [];

        foreach ($orders as $order) {
            $this->assertDeliveryDate($order, $changes);

            $stored = $this->changed($order, $changes);

            $this->assertOffered($order, $stored, $changes, $lookup);

            $validated[] = [$order, $this->columns($stored, $changes)];
        }

        return $validated;
    }

    /** @return array<string,mixed> */
    private function columns(array $stored, OptionChanges $changes): array
    {
        $columns = [Config::FIELD_DELIVERY_OPTIONS => json_encode($stored, JSON_THROW_ON_ERROR)];

        if (null !== $changes->carrier()) {
            $columns[Config::FIELD_MYPARCEL_CARRIER] = $changes->carrier();
        }

        // A pickup takes its date from the location, so a delivery type change moves the date too.
        if (null !== $changes->deliveryDate() || null !== $changes->deliveryType()) {
            $columns[Config::FIELD_DROP_OFF_DAY] = self::dropOffDay($stored['date']);
        }

        return $columns;
    }

    /** The order's stored options in the current shape, with the changes applied. */
    private function changed(Order $order, OptionChanges $changes): array
    {
        $raw    = json_decode((string) $order->getData(Config::FIELD_DELIVERY_OPTIONS), true);
        $stored = $this->currentShape(is_array($raw) ? $raw : [], $order, null !== $changes->carrier() || null !== $changes->deliveryType());

        if (null !== $changes->carrier()) {
            $stored = DeliveryOptions::withCarrier($stored, $changes->carrier());
        }

        if (null !== $changes->packageType()) {
            $stored['packageType'] = $changes->packageType();
        }

        $stored['shipmentOptions'] = (array) ($stored['shipmentOptions'] ?? []);
        $merchantOptions           = (array) ($stored['merchantOptions'] ?? []);

        foreach ($changes->options() as $option => $on) {
            $stored['shipmentOptions'][$option] = $on;
            $merchantOptions = array_diff($merchantOptions, [$option]);

            if ($on) {
                $merchantOptions[] = $option;
            }
        }

        if (null !== $changes->insurance()) {
            $stored['shipmentOptions'][ShipmentOption::INSURANCE] = $changes->insurance();
            $merchantOptions = array_diff($merchantOptions, [ShipmentOption::INSURANCE]);

            // The old checkout stored an amount too, so only this list says the merchant chose it.
            if (0 < $changes->insurance()) {
                $merchantOptions[] = ShipmentOption::INSURANCE;
            }
        }

        unset($stored['merchantOptions']);

        if ([] !== $merchantOptions) {
            $stored['merchantOptions'] = array_values($merchantOptions);
        }

        if (null !== $changes->labelAmount()) {
            $stored['labelAmount'] = $changes->labelAmount();
        }

        if (null !== $changes->digitalStampWeight()) {
            $stored['digitalStampWeight'] = $changes->digitalStampWeight();
        }

        if (OptionChanges::NO_DELIVERY_DATE === $changes->deliveryDate()) {
            $stored['date'] = null;
        } elseif (null !== $changes->deliveryDate()) {
            $stored['date'] = $changes->deliveryDate() . ' 00:00:00';
        }

        if (null !== $changes->deliveryType()) {
            $stored = $this->withDelivery($order, $stored, $changes);
        }

        return $this->withDimensions($stored, $changes->dimensions());
    }

    /**
     * The changed delivery type. A pickup gets its location, in the checkout's shape, and its
     * earliest date from the API, so a posted address is never stored.
     *
     * @throws LocalizedException
     */
    private function withDelivery(Order $order, array $stored, OptionChanges $changes): array
    {
        if (DeliveryType::PICKUP_NAME !== $changes->deliveryType()) {
            return DeliveryOptions::asHomeDelivery($stored, $changes->deliveryType());
        }

        $location = null === $changes->pickupLocationCode() ? null : $this->findPickupLocation(
            $order,
            self::carrierOf($order, $stored),
            $changes->pickupLocationCode()
        );

        if (null === $location) {
            throw new LocalizedException(__('%1: the pickup location is not available. Choose one from the list.', $order->getIncrementId()));
        }

        $stored['deliveryType']   = DeliveryType::PICKUP_NAME;
        $stored['isPickup']       = true;
        $stored['pickupLocation'] = PickupLocation::fromCheckoutData($location)->toArray();
        $stored['date']           = null === $location['date'] ? null : $location['date'] . ' 00:00:00';

        return $stored;
    }

    /** The stored carrier, else the order's default one. */
    private static function carrierOf(Order $order, array $stored): string
    {
        return (string) ($stored['carrier'] ?? (new DefaultOptions($order))->getCarrierName());
    }

    /** @throws LocalizedException when MyParcel cannot list the locations */
    private function findPickupLocation(Order $order, string $carrier, string $code): ?array
    {
        try {
            return $this->pickupLocations->find($order, $carrier, $code);
        } catch (RuntimeException $e) {
            throw new LocalizedException(__('%1: MyParcel could not load the pickup locations. Try again.', $order->getIncrementId()), $e);
        }
    }

    /** A dimension of 0 removes it; no dimension left removes the key. */
    private function withDimensions(array $stored, array $dimensions): array
    {
        $saved = (array) ($stored['physicalProperties'] ?? []);

        foreach ($dimensions as $dimension => $value) {
            if (0 === $value) {
                unset($saved[$dimension]);
            } else {
                $saved[$dimension] = $value;
            }
        }

        unset($stored['physicalProperties']);

        return [] === $saved ? $stored : $stored + ['physicalProperties' => $saved];
    }

    /** The checkout's rule, so the grid's drop-off day follows a changed date. Null without a date. */
    private static function dropOffDay(?string $date): ?string
    {
        return null === $date ? null : date('Y-m-d', DeliveryRepository::dropOffTimestampFor((int) strtotime($date)));
    }

    /**
     * Tomorrow at the earliest: the export sends no date for an earlier one, and saying so here beats
     * a label without the date the merchant picked.
     *
     * @throws LocalizedException
     */
    private function assertDeliveryDate(Order $order, OptionChanges $changes): void
    {
        $date = $changes->deliveryDate();

        if (null === $date || OptionChanges::NO_DELIVERY_DATE === $date) {
            return;
        }

        if ($date < $this->dating->tomorrow($order)) {
            throw new LocalizedException(__('%1: the delivery date must be tomorrow or later.', $order->getIncrementId()));
        }
    }

    /**
     * Through the adapter, so legacy and camelCase data come out in the one shape a change can be
     * merged into. A pickup that cannot be read is only repaired by a new carrier or delivery.
     *
     * @throws LocalizedException
     */
    private function currentShape(array $raw, Order $order, bool $repairs): array
    {
        try {
            return DeliveryOptionsFactory::create($raw)->toArray();
        } catch (BadMethodCallException $e) {
            return DeliveryOptions::defaults()->toArray();
        } catch (InvalidArgumentException $e) {
            if (! $repairs) {
                throw new LocalizedException(__(
                    '%1: the pickup location of this order cannot be read. Choose another delivery or carrier.',
                    $order->getIncrementId()
                ));
            }

            return DeliveryOptionsFactory::create(DeliveryOptions::asHomeDelivery($raw))->toArray();
        }
    }

    /**
     * Only what the merchant switches on or changes is checked: switching an option off is always
     * allowed. Unverified capabilities check nothing, the same as the New Shipment form.
     *
     * @throws LocalizedException
     */
    private function assertOffered(Order $order, array $stored, OptionChanges $changes, ShapeLookup $lookup): void
    {
        $address     = $order->getShippingAddress();
        $country     = $address ? (string) $address->getCountryId() : '';
        $storeId     = (int) $order->getStoreId();
        $carrier     = self::carrierOf($order, $stored);
        $packageType = $stored['packageType'] ?? null;
        $refuse      = static function (string $what) use ($order): LocalizedException {
            return new LocalizedException(__('%1: %2 is not available for this account.', $order->getIncrementId(), $what));
        };

        $broad = $lookup->forShape($storeId, $country);

        if ($broad->isPermissive()) {
            return;
        }

        if (null !== $changes->carrier()
            && (! Carrier::isExportable($carrier) || ! in_array($carrier, $broad->carriers(), true))) {
            throw $refuse($carrier);
        }

        if (null !== $changes->packageType() && ! $broad->hasPackageType($carrier, (string) $packageType)) {
            throw $refuse((string) $packageType);
        }

        $shape = $lookup->forShape($storeId, $country, $packageType, $carrier);

        foreach (array_keys(array_filter($changes->options())) as $option) {
            if (! $shape->hasOption($carrier, $packageType, $option)) {
                throw $refuse($option);
            }
        }

        $this->assertInsurance($shape, $carrier, $packageType, $changes->insurance(), $refuse);

        if (null !== $changes->deliveryType()) {
            // The options that are on go with it: an age check rules out morning and evening.
            $ticked   = array_keys(array_filter((array) ($stored['shipmentOptions'] ?? []), static fn($on): bool => true === $on));
            $delivery = $lookup->forShape($storeId, $country, $packageType, $carrier, null, $ticked);

            // No result for the carrier means the options that are on do not go together, which
            // is not the delivery type's fault: the answer without options decides, as in the modal.
            if (! $delivery->isPermissive() && ! in_array($carrier, $delivery->carriers(), true)) {
                $delivery = $shape;
            }

            if (! $delivery->hasDeliveryType($carrier, $packageType, $changes->deliveryType())) {
                throw $refuse(DeliveryList::labelFor($changes->deliveryType()));
            }
        }
    }

    private function assertInsurance(CapabilitySet $shape, string $carrier, ?string $packageType, ?int $amount, callable $refuse): void
    {
        if (null === $amount) {
            return;
        }

        $offered = $shape->hasOption($carrier, $packageType, ShipmentOption::INSURANCE);

        // Zero is "no insurance": always fine where it is not offered, and refused below when required.
        if (0 === $amount && ! $offered) {
            return;
        }

        if (! $offered) {
            throw $refuse(ShipmentOption::INSURANCE);
        }

        $range = InsuranceRange::fromOptionValue($shape->optionValue($carrier, $packageType, ShipmentOption::INSURANCE));

        if (null !== $range && ! $range->allows($amount)) {
            throw $refuse(sprintf('%s € %d', ShipmentOption::INSURANCE, $amount));
        }
    }
}
