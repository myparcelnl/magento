<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Services\CountryCodes;

/**
 * Resolves the package type one cart ships as with one carrier.
 *
 * resolve() takes a carrier **name**, because the PostNL international check needs it; the other
 * methods take a carrier config path, as CartShippingRules does. Holds no state of its own, so one
 * instance serves every carrier in a checkout.
 */
class PackageTypeResolver
{
    private const DEFAULT_MAXIMUM_MAILBOX_WEIGHT       = 2000;
    private const MAXIMUM_DIGITAL_STAMP_WEIGHT         = 2000;
    private const DEFAULT_MAXIMUM_PACKAGE_SMALL_WEIGHT = 2000;

    /** Below this a configured kilo weight counts as unset. */
    private const KILO_EPSILON = 0.00001;

    private Config                     $config;
    private ProductAttributes          $attributes;
    private Weight                     $weight;
    private PostnlMailboxInternational $postnlMailboxInternational;

    public function __construct(
        Config                     $config,
        ProductAttributes          $attributes,
        Weight                     $weight,
        PostnlMailboxInternational $postnlMailboxInternational
    )
    {
        $this->config                     = $config;
        $this->attributes                 = $attributes;
        $this->weight                     = $weight;
        $this->postnlMailboxInternational = $postnlMailboxInternational;
    }

    /**
     * Get the package types the carrier may ship the cart as: those $allowed allows, narrowed to the
     * groups the carrier switched on. Abroad the mailbox also needs `mailbox/international_active`,
     * which the settings only show while `mailbox/active` is on.
     *
     * @param array<string,bool> $allowed package type name => what capabilities and forced options allow
     */
    public function candidates(array $allowed, string $carrierPath, string $country, ?int $storeId): PackageTypeCandidates
    {
        $mailboxToggles = CountryCodes::CC_NL === $country
            ? ['mailbox/active']
            : ['mailbox/active', 'mailbox/international_active'];

        /** @var array<string, string[]> $toggles package type name => config keys that must all be on */
        $toggles = [
            PackageType::DIGITAL_STAMP_NAME => ['digital_stamp/active'],
            PackageType::MAILBOX_NAME       => $mailboxToggles,
            PackageType::PACKAGE_SMALL_NAME => ['package_small/active'],
        ];

        $candidates = PackageTypeCandidates::none();

        foreach ($toggles as $packageTypeName => $keys) {
            if (($allowed[$packageTypeName] ?? false) && $this->allOn($carrierPath, $keys, $storeId)) {
                $candidates = $candidates->with($packageTypeName);
            }
        }

        return $candidates;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function resolve(
        array                 $items,
        string                $carrierName,
        string                $country,
        PackageTypeCandidates $candidates,
        ?int                  $storeId = null
    ): string
    {
        $carrierPath = Config::CARRIERS_XML_PATH_MAP[$carrierName];

        $maxMailboxWeight = $this->maxMailboxWeight($carrierPath, $storeId);

        $this->attributes->preloadItems($items);

        $weight       = $this->weight->itemsWeight($items);
        $percentage   = 0.0;
        $digitalStamp = true;

        foreach ($items as $item) {
            $itemQty    = (float) $item->getQty();
            $itemWeight = (float) $item->getWeight();

            if ($itemQty < 1) {
                continue;
            }

            if ($digitalStamp && ! $this->attributes->itemValue($item, 'digital_stamp')) {
                $digitalStamp = false;
            }

            if (100 < $percentage) {
                continue;
            }

            // No row means 0, so the weight decides. A product gets the -1 default only when it is
            // saved in the admin.
            $mailboxQty = $this->attributes->itemValue($item, 'fit_in_mailbox') ?? 0;

            if (-1 === $mailboxQty) {
                $percentage = 101;
                continue;
            }

            if (0 === $mailboxQty && 0.0 !== $itemWeight) {
                $mailboxQty = (int) ($maxMailboxWeight / $itemWeight);
            }

            if (0 !== $mailboxQty) {
                $percentage += $itemQty * 100 / $mailboxQty;
            }
        }

        if ($digitalStamp
            && $candidates->has(PackageType::DIGITAL_STAMP_NAME)
            && CountryCodes::CC_NL === $country
            && $this->weight->convertToGrams($weight) <= $this->maxDigitalStampWeight($carrierPath, $storeId)
        ) {
            return PackageType::DIGITAL_STAMP_NAME;
        }

        if ($this->mailboxAllowedTo($country, $carrierName, $storeId)
            && $candidates->has(PackageType::MAILBOX_NAME)
            && $weight <= $maxMailboxWeight
            && $percentage <= 100
        ) {
            return PackageType::MAILBOX_NAME;
        }

        if ($candidates->has(PackageType::PACKAGE_SMALL_NAME)
            && $weight <= $this->maxPackageSmallWeight($carrierPath, $storeId)
        ) {
            return PackageType::PACKAGE_SMALL_NAME;
        }

        return PackageType::PACKAGE_NAME;
    }

    /**
     * Check that the cart is not heavier than the carrier's mailbox maximum. False when the carrier
     * has no mailbox group, or has one that is switched off.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function fitsMailboxWeight(array $items, string $carrierPath, ?int $storeId): bool
    {
        $maxMailboxWeight = $this->maxMailboxWeight($carrierPath, $storeId);

        return $maxMailboxWeight > 0.0 && $this->weight->itemsWeight($items) <= $maxMailboxWeight;
    }

    /** Zero when the carrier has no mailbox group, or has one that is switched off. */
    private function maxMailboxWeight(string $carrierPath, ?int $storeId): float
    {
        $settings = $this->groupIfActive("{$carrierPath}mailbox", $storeId);

        if (null === $settings) {
            return 0.0;
        }

        return $this->configuredWeight($settings['weight'] ?? '', self::DEFAULT_MAXIMUM_MAILBOX_WEIGHT);
    }

    /** Zero when the carrier has no digital stamp group, or has one that is switched off. */
    private function maxDigitalStampWeight(string $carrierPath, ?int $storeId): float
    {
        return null === $this->groupIfActive("{$carrierPath}digital_stamp", $storeId)
            ? 0.0
            : (float) self::MAXIMUM_DIGITAL_STAMP_WEIGHT;
    }

    /** Zero when the carrier has no package small group, or has one that is switched off. */
    private function maxPackageSmallWeight(string $carrierPath, ?int $storeId): float
    {
        $settings = $this->groupIfActive("{$carrierPath}package_small", $storeId);

        if (null === $settings) {
            return 0.0;
        }

        return $this->configuredWeight($settings['weight'] ?? '', self::DEFAULT_MAXIMUM_PACKAGE_SMALL_WEIGHT);
    }

    /** @param string[] $keys config keys below the carrier path */
    private function allOn(string $carrierPath, array $keys, ?int $storeId): bool
    {
        foreach ($keys as $key) {
            if ('1' !== $this->config->getConfigValue($carrierPath . $key, $storeId)) {
                return false;
            }
        }

        return true;
    }

    /** Get the group when its own `active` is on. */
    private function groupIfActive(string $path, ?int $storeId): ?array
    {
        $settings = $this->config->getConfigValue($path, $storeId);

        if (! is_array($settings) || ! array_key_exists('active', $settings) || '1' !== $settings['active']) {
            return null;
        }

        return $settings;
    }

    /** @param mixed $configured the group's `weight` value, in the merchant's own unit */
    private function configuredWeight($configured, int $default): float
    {
        $weight = abs((float) str_replace(',', '.', (string) $configured));

        if ($this->weight->unitIsKilo()) {
            return $weight < self::KILO_EPSILON ? $default / 1000.0 : $weight;
        }

        return (float) ((int) $weight ?: $default);
    }

    private function mailboxAllowedTo(string $country, string $carrierName, ?int $storeId): bool
    {
        if (CountryCodes::CC_NL === $country) {
            return true;
        }

        return CarrierPostNL::NAME === $carrierName && $this->postnlMailboxInternational->isEnabled($storeId);
    }
}
