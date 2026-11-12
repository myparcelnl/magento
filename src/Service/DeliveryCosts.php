<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Model\Source\PricedDeliveryType;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\Services\CountryCodes;

class DeliveryCosts
{
    use NeedsQuoteProps;

    private Weight      $weight;
    private Config      $config;
    private Tax         $tax;
    private OrderAmount $orderAmount;

    /** The checkout prices every delivery type of every carrier, so decode the matrix once. */
    private array $decodedMatrixCache = [];

    /**
     * The points will be added to the total points for each condition that is met.
     * The price associated with the condition with the most points will be used.
     * Distribute points in a way that the most specific condition has the most points, and less specific conditions
     * cannot override more specific conditions.
     *
     * The country conditions outrank all others together, so the price of a country changes only through a rule for
     * that country. Every condition a rule names must be met, or the rule is ruled out. A rule without conditions is
     * the merchant's default, and without any rule left the carrier's shipping cost applies.
     */
    private const CONDITIONS = [
        'country'              => 256,
        'country_part_of'      => 128,
        'delivery_type'        => 64,
        'minimum_order_amount' => 32,
        'maximum_order_amount' => 16,
        'package_type'         => 8,
        'carrier_name'         => 4,
        'maximum_weight'       => 2,
        'unspecified'          => 1, // when a condition is not specified, it should be considered met, but least specific
    ];

    /** The quote values every calculate() call must supply, see getBasePrice(). */
    private const REQUIRED_CONDITIONS = ['carrier_name', 'package_type', 'country', 'weight', 'order_amount', 'delivery_type'];

    public function __construct(Weight $weight, Config $config, Tax $tax, OrderAmount $orderAmount)
    {
        $this->weight      = $weight;
        $this->config      = $config;
        $this->tax         = $tax;
        $this->orderAmount = $orderAmount;
    }

    /**
     * Returns the base price with Magento tax settings applied, for displaying to the client.
     *
     * @param Quote       $quote to get the weight and default carrier, package type and country, and calculate tax
     * @param string|null $carrierName override carrier from quote if you want
     * @param string|null $packageTypeName override package type from quote if you want
     * @param string|null $countryCode override country from quote->shippingAddress if you want
     * @param string|null $deliveryTypeName the delivery type to price, standard when null
     * @param float|null  $orderAmount see getOrderAmount(), computed from the quote when null
     * @return float
     * @uses DeliveryCosts::getBasePrice()
     */
    public function getBasePriceForClient(Quote $quote, ?string $carrierName = null, ?string $packageTypeName = null, ?string $countryCode = null, ?string $deliveryTypeName = null, ?float $orderAmount = null): float
    {
        $price = $this->getBasePrice($quote, $carrierName, $packageTypeName, $countryCode, $deliveryTypeName, $orderAmount);

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
     * @param float|null  $orderAmount see getOrderAmount(), computed from the quote when null
     * @return float
     */
    public function getBasePrice(Quote $quote, ?string $carrierName = null, ?string $packageTypeName = null, ?string $countryCode = null, ?string $deliveryTypeName = null, ?float $orderAmount = null): float
    {
        if ($this->config->getGeneralConfig('matrix/use_free_shipping')
            && $this->isFreeShippingAvailable()
        ) {
            return 0.0;
        }
        $defaultOptions = new DefaultOptions($quote);

        $carrierName = $carrierName ?? $defaultOptions->getCarrierName();
        $packageType = AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageTypeName] ?? $defaultOptions->getPackageType();
        $countryCode = $countryCode ?? $quote->getShippingAddress()->getCountryId() ?? AbstractConsignment::CC_NL;
        $weight      = $this->weight->getEmptyPackageWeightInGrams($packageType)
                       + $this->weight->getQuoteWeightInGrams($quote);

        return $this->calculate(
            [
                'carrier_name'  => $carrierName,
                'package_type'  => $packageType,
                'country'       => $countryCode,
                'weight'        => $weight,
                'order_amount'  => $orderAmount ?? $this->getOrderAmount($quote),
                'delivery_type' => $deliveryTypeName ?? PricedDeliveryType::DEFAULT,
            ]
        );
    }

    /** Get the subtotal after discount, including tax, which the order amount conditions compare, see OrderAmount. */
    public function getOrderAmount(Quote $quote): float
    {
        return $this->orderAmount->get($quote);
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

        // a type outside PricedDeliveryType::NAMES is a fee on top of standard delivery
        if (! in_array($conditions['delivery_type'], PricedDeliveryType::NAMES, true)) {
            $conditions['delivery_type'] = PricedDeliveryType::DEFAULT;
        }

        $json = $this->config->getGeneralConfig('matrix/delivery_costs');
        if (null === $json) {
            return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
        }

        $matrix = $this->decodedMatrixCache[$json] ??= (json_decode($json, true, 8) ?? []);
        $return = [];

        foreach ($matrix as $definition) {
            if (! isset($definition['conditions']) || ! is_array(($definedConditions = $definition['conditions']))) {
                continue;
            }

            $totalPoints = $this->scoreDefinition($conditions, $definedConditions);

            if (null === $totalPoints) {
                continue;
            }

            // the tightest thresholds of this definition, all met, for the tie-break in the sort below
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
     * Adds the points of every condition of a definition, see self::CONDITIONS.
     *
     * @param array $conditions actual conditions from the quote
     * @param array $definedConditions from the matrix
     * @return int|null null when a condition of the definition is not met
     */
    private function scoreDefinition(array $conditions, array $definedConditions): ?int
    {
        $totalPoints = 0;
        foreach (array_keys(self::CONDITIONS) as $condition) {
            if ('unspecified' === $condition) {
                continue;
            }

            $score = $this->scoreCondition($condition, $conditions, $definedConditions);

            if (0 === $score) {
                return null;
            }

            $totalPoints += $score;
        }

        return $totalPoints;
    }

    /**
     * Scores a specific condition against the defined conditions in the matrix, using the actual conditions from the quote.
     * Several conditions of one type match when one of them matches, except the order amounts: a rule must meet each.
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
                // an empty or non-numeric threshold is an unfinished rule, never met, instead of (float) '' matching every order
                case 'minimum_order_amount':
                    if (! is_numeric($value) || (float) $conditions['order_amount'] < (float) $value) {
                        return 0;
                    }
                    break;
                case 'maximum_order_amount':
                    if (! is_numeric($value) || (float) $conditions['order_amount'] >= (float) $value) {
                        return 0;
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

        // every order amount threshold was met
        if (in_array($condition, ['minimum_order_amount', 'maximum_order_amount'], true)) {
            return self::CONDITIONS[$condition];
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
     * @param float $price in Euros
     *
     * @return int price in cents
     */
    public static function getPriceInCents(float $price): int
    {
        return (int) ($price * 100);
    }
}
