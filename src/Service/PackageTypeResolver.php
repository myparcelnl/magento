<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * Which package type one cart ships as with one carrier.
 *
 * Takes a carrier **name**, unlike CartShippingRules, which takes a config path. The name is what
 * the PostNL international question needs, and resolve() is only ever asked about a carrier that is
 * active, so the name always maps to a path.
 *
 * Every answer comes from the arguments. Nothing is remembered between calls, which is what lets one
 * instance serve every carrier in a checkout without the previous carrier's weights leaking into the
 * next one.
 */
class PackageTypeResolver
{
    use ReadsProductAttributes;

    public const DEFAULT_MAXIMUM_MAILBOX_WEIGHT = 2000;
    public const MAXIMUM_DIGITAL_STAMP_WEIGHT   = 2000;
    public const MAXIMUM_PACKAGE_SMALL_WEIGHT   = 2000;

    /** Below this a configured kilo weight counts as unset. */
    private const KILO_EPSILON = 0.00001;

    private Config                     $config;
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

        // Read before the loop and whatever the candidates say: the per-item mailbox count divides
        // by it even when the mailbox was never a candidate.
        $maxMailboxWeight = $this->maxMailboxWeight($carrierPath, $storeId);

        $this->warmAttributes($items);

        $weight       = 0.0;
        $percentage   = 0.0;
        $digitalStamp = true;

        foreach ($items as $item) {
            $itemQty    = (float) $item->getQty();
            $itemWeight = (float) $item->getWeight();

            if ($itemQty < 1) {
                continue;
            }

            if ($itemWeight > 0) {
                $weight += $itemWeight * $itemQty;
            }

            if ($digitalStamp && ! $this->attributeOf($item, 'digital_stamp')) {
                $digitalStamp = false;
            }

            if (100 < $percentage) {
                continue;
            }

            // No row means the attribute's own default, which UpgradeData sets to -1.
            $mailboxQty = $this->attributeOf($item, 'fit_in_mailbox') ?? -1;

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
            && CountryCode::CC_NL === $country
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
     * The cart's weight in the merchant's own unit, which is what the mailbox and package small
     * ceilings are compared against.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function cartWeight(array $items): float
    {
        $weight = 0.0;

        foreach ($items as $item) {
            $itemQty    = (float) $item->getQty();
            $itemWeight = (float) $item->getWeight();

            if ($itemQty < 1 || $itemWeight <= 0) {
                continue;
            }

            $weight += $itemWeight * $itemQty;
        }

        return $weight;
    }

    /** Zero when the carrier has no mailbox group, or has one that is switched off. */
    public function maxMailboxWeight(string $carrierPath, ?int $storeId): float
    {
        $settings = $this->groupIfActive("{$carrierPath}mailbox", $storeId);

        if (null === $settings) {
            return 0.0;
        }

        return $this->configuredWeight($settings['weight'] ?? '', self::DEFAULT_MAXIMUM_MAILBOX_WEIGHT, $storeId);
    }

    /** Zero when the carrier has no digital stamp group, or has one that is switched off. */
    public function maxDigitalStampWeight(string $carrierPath, ?int $storeId): float
    {
        return null === $this->groupIfActive("{$carrierPath}digital_stamp", $storeId)
            ? 0.0
            : (float) self::MAXIMUM_DIGITAL_STAMP_WEIGHT;
    }

    /** Zero when the carrier has no package small group, or has one that is switched off. */
    public function maxPackageSmallWeight(string $carrierPath, ?int $storeId): float
    {
        $settings = $this->groupIfActive("{$carrierPath}package_small", $storeId);

        if (null === $settings) {
            return 0.0;
        }

        return $this->configuredWeight($settings['weight'] ?? '', self::MAXIMUM_PACKAGE_SMALL_WEIGHT, $storeId);
    }

    /**
     * The group's own `active` decides whether a maximum weight exists at all — not the country, and
     * not `international_active`. A carrier switched off at home therefore carries a maximum of zero
     * abroad, and only a weightless cart fits it.
     */
    private function groupIfActive(string $path, ?int $storeId): ?array
    {
        $settings = $this->config->getConfigValue($path, $storeId);

        if (! is_array($settings) || ! array_key_exists('active', $settings) || '1' !== $settings['active']) {
            return null;
        }

        return $settings;
    }

    private function configuredWeight($configured, int $default, ?int $storeId): float
    {
        $weight = abs((float) str_replace(',', '.', (string) $configured));

        if ('kilo' === $this->config->getGeneralConfig('print/weight_indication', $storeId)) {
            return $weight < self::KILO_EPSILON ? $default / 1000.0 : $weight;
        }

        return (float) ((int) $weight ?: $default);
    }

    /**
     * Asked before the candidates are consulted, so a PostNL order abroad still resolves its account
     * even when the mailbox was already ruled out. That read logs when it fails, and losing the log
     * would hide a broken account settings row.
     */
    private function mailboxAllowedTo(string $country, string $carrierName, ?int $storeId): bool
    {
        if (CountryCode::CC_NL === $country) {
            return true;
        }

        return CarrierPostNL::NAME === $carrierName && $this->postnlMailboxInternational->isEnabled($storeId);
    }
}
