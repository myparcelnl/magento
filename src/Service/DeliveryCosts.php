<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Services\CountryCodes;

class DeliveryCosts
{
    use NeedsQuoteProps;

    private Weight $weight;
    private Config $config;
    private Tax    $tax;

    /**
     * The points will be added to the total points for each condition that is met.
     * The price associated with the condition with the most points will be used.
     * Distribute points in a way that the most specific condition has the most points, and less specific conditions
     * cannot override more specific conditions.
     *
     * The order amount and delivery type conditions outrank all others together, so a rule holding one of them wins
     * from a rule without it. Unlike the others they also rule a definition out when not met: a free shipping
     * threshold or a pickup price must never reach an order below the threshold or a home delivery.
     */
    private const CONDITIONS = [
        'delivery_type'        => 256,
        'minimum_order_amount' => 128,
        'maximum_order_amount' => 64,
        'country'              => 32,
        'country_part_of'      => 16,
        'package_type'         => 8,
        'carrier_name'         => 4,
        'maximum_weight'       => 2,
        'unspecified'          => 1, // when a condition is not specified, it should be considered met, but least specific
    ];

    /** The quote values every calculate() call must supply, see getBasePrice(). */
    private const REQUIRED_CONDITIONS = ['carrier_name', 'package_type', 'country', 'weight', 'order_amount', 'delivery_type'];

    /** Not met, these rule a definition out instead of scoring 0, see self::CONDITIONS. */
    private const EXCLUDING_CONDITIONS = ['delivery_type', 'minimum_order_amount', 'maximum_order_amount'];

    public function __construct(Weight $weight, Config $config, Tax $tax)
    {
        $this->weight = $weight;
        $this->config = $config;
        $this->tax    = $tax;
    }

    /**
     * One request prices several delivery types for several carriers over the same quote, so the amounts summed
     * from the items are kept per quote (keyed by object id) and the matrix is decoded once per JSON value.
     */
    private array $orderAmountPerQuote   = [];
    private array $weightInGramsPerQuote = [];
    private array $matrixPerJson         = [];

    /**
     * Returns the base price with Magento tax settings applied, for displaying to the client.
     *
     * @param Quote       $quote to get the weight and default carrier, package type and country, and calculate tax
     * @param string|null $carrierName override carrier from quote if you want
     * @param string|null $packageTypeName override package type from quote if you want
     * @param string|null $countryCode override country from quote->shippingAddress if you want
     * @param string|null $deliveryTypeName the delivery type to price, standard when null
     * @return float
     * @uses DeliveryCosts::getBasePrice()
     */
    public function getBasePriceForClient(Quote $quote, ?string $carrierName = null, ?string $packageTypeName = null, ?string $countryCode = null, ?string $deliveryTypeName = null): float
    {
        $price = $this->getBasePrice($quote, $carrierName, $packageTypeName, $countryCode, $deliveryTypeName);

        return $this->tax->shippingPrice($price, $quote);
    }

    /**
     * Returns the base price bare, without any tax settings applied, used inside Magento (it will apply tax settings later).
     *
     * @param Quote       $quote to get the weight and default carrier, package type and country, and calculate tax
     * @param string|null $carrierName override carrier from quote if you want
     * @param string|null $packageTypeName override package type from quote if you want
     * @param string|null $countryCode override country from quote->shippingAddress if you want
     * @param string|null $deliveryTypeName the delivery type to price, standard when null
     * @return float
     */
    public function getBasePrice(Quote $quote, ?string $carrierName = null, ?string $packageTypeName = null, ?string $countryCode = null, ?string $deliveryTypeName = null): float
    {
        if ($this->config->getGeneralConfig('matrix/use_free_shipping')
            && $this->isFreeShippingAvailable()
        ) {
            return 0.0;
        }
        $defaultOptions = new DefaultOptions($quote);

        $carrierName = $carrierName ?? $defaultOptions->getCarrierName();
        $packageType = PackageType::NAMES_IDS_MAP[$packageTypeName] ?? $defaultOptions->getPackageType();
        $countryCode = $countryCode ?? $quote->getShippingAddress()->getCountryId() ?? CountryCode::CC_NL;
        $weight      = $this->weight->getEmptyPackageWeightInGrams($packageType)
                       + ($this->weightInGramsPerQuote[spl_object_id($quote)] ??= $this->weight->getQuoteWeightInGrams($quote));

        return $this->calculate(
            [
                'carrier_name'  => $carrierName,
                'package_type'  => $packageType,
                'country'       => $countryCode,
                'weight'        => $weight,
                'order_amount'  => $this->getOrderAmount($quote),
                'delivery_type' => $deliveryTypeName ?? DeliveryType::DEFAULT_NAME,
            ]
        );
    }

    /**
     * The amount the order amount conditions compare: the subtotal after discount, including tax, in the currency of
     * the store view. Shipping and its discount are no part of it. Computed once per quote: the items do not change
     * between the delivery type lookups of one request.
     */
    private function getOrderAmount(Quote $quote): float
    {
        return $this->orderAmountPerQuote[spl_object_id($quote)] ??= $this->computeOrderAmount($quote);
    }

    /**
     * Summed from the items, whose totals (subtotal, subtotal including tax and discount) Magento collects
     * before it collects the shipping rates.
     */
    private function computeOrderAmount(Quote $quote): float
    {
        $storeId = (int) $quote->getStoreId();
        // the item discount excludes tax, unless Magento applies discounts on prices including tax
        $discountIncludesTax = '1' === (string) $this->config->getConfigValue('tax/calculation/discount_tax', $storeId);
        $taxAfterDiscount    = '0' !== (string) $this->config->getConfigValue('tax/calculation/apply_after_discount', $storeId);
        $amount              = 0.0;

        foreach ($quote->getAllVisibleItems() as $item) {
            $rowTotal        = (float) $item->getRowTotal();
            $rowTotalInclTax = (float) ($item->getRowTotalInclTax() ?? $rowTotal);
            $discount        = (float) $item->getDiscountAmount();

            if (! $discountIncludesTax && $taxAfterDiscount && $rowTotal > 0) {
                // tax calculated after the discount lowers the tax too, so raise the discount by the item's tax rate
                $discount *= $rowTotalInclTax / $rowTotal;
            }

            $amount += $rowTotalInclTax - $discount;
        }

        return max(0.0, $amount);
    }

    /**
     * Calculates the actual price based on the conditions, using self::CONDITIONS to determine the hierarchy.
     * If no conditions are met, the default shipping cost from the Magento carrier settings will be used.
     *
     * @param array $conditions
     * @return float
     */
    private function calculate(array $conditions): float
    {
        foreach (self::REQUIRED_CONDITIONS as $required) {
            if (! isset($conditions[$required])) {
                throw new \InvalidArgumentException('Missing required conditions');
            }
        }

        $json = $this->config->getGeneralConfig('matrix/delivery_costs');
        if (null === $json) {
            return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
        }

        $matrix = $this->matrixPerJson[$json] ??= (json_decode($json, true, 8) ?? []);
        $return = [];

        foreach ($matrix as $definition) {
            if (! isset($definition['conditions']) || ! is_array(($definedConditions = $definition['conditions']))) {
                continue;
            }

            // calculate relative weight of this option (definition) using hierarchy points by walking through the conditions
            $totalPoints = 0;
            foreach (self::CONDITIONS as $condition => $points) {
                if ('unspecified' === $condition) {
                    continue;
                }

                $score = $this->scoreCondition($condition, $conditions, $definedConditions);

                if (0 === $score && in_array($condition, self::EXCLUDING_CONDITIONS, true)) {
                    continue 2;
                }

                $totalPoints += $score;
            }

            // the tightest met thresholds of this definition, for the tie-break in the sort below
            $minimum = -INF;
            $maximum = INF;
            foreach ($definedConditions as $definedCondition) {
                $value = (float) ($definedCondition['value'] ?? 0);
                if ('minimum_order_amount' === ($definedCondition['type'] ?? '')) {
                    $minimum = max($minimum, $value);
                }
                if ('maximum_order_amount' === ($definedCondition['type'] ?? '')) {
                    $maximum = min($maximum, $value);
                }
            }

            $return[] = [
                'definition' => $definition,
                // the sort rank: points first, between equal points the tightest met threshold wins — the
                // highest minimum, then the lowest maximum (negated, so every key ranks higher-is-better)
                'rank'       => [$totalPoints, $minimum, -$maximum],
            ];
        }

        if (0 === count($return)) {
            // when nothing matched, act as a flat rate using the provided shipping cost
            return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
        }

        // sort the highest rank first, then return the price from the first result, so tiered
        // thresholds price by the tier the merchant meant, whatever the order of the rules
        usort($return, static function (array $left, array $right) {
            return $right['rank'] <=> $left['rank'];
        });

        return (float) $return[0]['definition']['price'];
    }

    /**
     * Scores a specific condition against the defined conditions in the matrix, using the actual conditions from the quote.
     *
     * @param string $condition the name of the condition from self::CONDITIONS
     * @param array  $conditions actual conditions from the quote
     * @param array  $definedConditions from the matrix, each condition is an associative array with 'type' and 'value' keys
     * @return int 0 when not met, or the points for the condition when met, or 1 when unspecified.
     */
    private function scoreCondition(string $condition, array $conditions, array $definedConditions): int
    {
        $conditionIsChecked = false;

        foreach ($definedConditions as $definedCondition) {
            $type = $definedCondition['type'] ?? '';

            if ($type !== $condition) {
                continue;
            }

            $conditionIsChecked = true;
            $value              = $definedCondition['value'] ?? '';

            switch ($condition) {
                // an empty or non-numeric threshold is an unfinished rule: never met, so the excluding
                // conditions rule the definition out instead of (float) '' making it match every order
                case 'minimum_order_amount':
                    if (is_numeric($value) && (float) $conditions['order_amount'] >= (float) $value) {
                        return self::CONDITIONS[$condition];
                    }
                    break;
                case 'maximum_order_amount':
                    if (is_numeric($value) && (float) $conditions['order_amount'] < (float) $value) {
                        return self::CONDITIONS[$condition];
                    }
                    break;
                case 'maximum_weight':
                    if ((float) $conditions['weight'] <= (float) $value) {
                        return self::CONDITIONS[$condition];
                    }
                    break;
                case 'country_part_of':
                    if (self::isCountryPartOf($conditions['country'], $value)) {
                        return self::CONDITIONS[$condition];
                    }
                    break;
                default:
                    if ($conditions[$condition] === $value) {
                        return self::CONDITIONS[$condition];
                    }
            }

        }

        // consider unspecified conditions as valid
        if (! $conditionIsChecked) {
            return self::CONDITIONS['unspecified'];
        }

        // condition was checked, but not met
        return 0;
    }

    /**
     * @param string       $needle the country code (2-letter ISO)
     * @param string|array $haystackDefinition string denoting an existing array or array of countries (2-letter ISO)
     * @return bool whether the country is part of the haystack
     */
    public static function isCountryPartOf(string $needle, $haystackDefinition): bool
    {
        switch ($haystackDefinition) {
            case is_array($haystackDefinition):// TODO make country_part_of an array, possibly?
                return in_array($needle, $haystackDefinition, true);
            case CountryCodes::ZONE_EU:
                return in_array($needle, CountryCodes::EU_COUNTRIES);
            case CountryCodes::ZONE_ROW:
                return ! in_array($needle, CountryCodes::EU_COUNTRIES);
            default:
                return $needle === $haystackDefinition;
        }
    }


    /**
     * Rounded, not truncated: 0.29 * 100 is 28.999999999999996 in binary floating point, and a cast
     * would make 29 cents into 28 — on a customs value, a delivery cost and an insured amount alike.
     *
     * @param float $price in Euros
     *
     * @return int price in cents
     */
    public static function getPriceInCents(float $price): int
    {
        return self::roundHalfUp($price * 100);
    }

    /**
     * Half up, spelled out rather than left to round().
     *
     * round() pre-rounded a value within epsilon of a .5 boundary up to it until PHP 8.3 and no
     * longer does, so 1.005 * 100, which is really 100.49999999999999, answered 101 on three PHP
     * versions and 100 on the fourth. Money may not depend on the PHP version, so every cent the
     * module rounds comes through here.
     */
    public static function roundHalfUp(float $value): int
    {
        return (int) floor($value + 0.5);
    }
}
