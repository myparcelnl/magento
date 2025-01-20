<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Services\CountryCodes;

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
     */
    private const CONDITIONS = [
        'country'         => 32,
        'country_part_of' => 16,
        'package_type'    => 8,
        'carrier_name'    => 4,
        'maximum_weight'  => 2,
        'unspecified'     => 1, // when a condition is not specified, it should be considered met, but least specific
    ];

    public function __construct(Weight $weight, Config $config, Tax $tax)
    {
        $this->weight = $weight;
        $this->config = $config;
        $this->tax    = $tax;
    }

    /**
     * Returns the base price with Magento tax settings applied, for displaying to the client.
     *
     * @param Quote       $quote to get the weight and default carrier, package type and country, and calculate tax
     * @param string|null $carrierName override carrier from quote if you want
     * @param string|null $packageTypeName override package type from quote if you want
     * @param string|null $countryCode override country from quote->shippingAddress if you want
     * @return float
     * @uses DeliveryCosts::getBasePrice()
     */
    public function getBasePriceForClient(Quote $quote, ?string $carrierName = null, ?string $packageTypeName = null, ?string $countryCode = null): float
    {
        $price = $this->getBasePrice($quote, $carrierName, $packageTypeName, $countryCode);

        return $this->tax->shippingPrice($price, $quote);
    }

    /**
     * Returns the base price bare, without any tax settings applied, used inside Magento (it will apply tax settings later).
     *
     * @param Quote       $quote to get the weight and default carrier, package type and country, and calculate tax
     * @param string|null $carrierName override carrier from quote if you want
     * @param string|null $packageTypeName override package type from quote if you want
     * @param string|null $countryCode override country from quote->shippingAddress if you want
     * @return float
     */
    public function getBasePrice(Quote $quote, ?string $carrierName = null, ?string $packageTypeName = null, ?string $countryCode = null): float
    {
        if ($this->isFreeShippingAvailable($quote)) {
            return 0.0;
        }
        $defaultOptions = new DefaultOptions($quote);

        $carrierName = $carrierName ?? $defaultOptions->getCarrierName();
        $packageType = AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageTypeName] ?? $defaultOptions->getPackageType();
        $countryCode = $countryCode ?? $quote->getShippingAddress()->getCountryId() ?? AbstractConsignment::CC_NL;
        $weight      = $this->weight->getEmptyPackageWeightInGrams($packageType)
                       + $this->weight->getQuoteWeightInGrams($quote);

        return $this->calculate([
                                    'carrier_name' => $carrierName,
                                    'package_type' => $packageType,
                                    'country'      => $countryCode,
                                    'weight'       => $weight,
                                ]);
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
        if (!isset($conditions['carrier_name'], $conditions['package_type'], $conditions['country'], $conditions['weight'])) {
            throw new \InvalidArgumentException('Missing required conditions');
        }

        $json = $this->config->getGeneralConfig('matrix/delivery_costs');
        if (null === $json) {
            return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
        }

        $matrix = json_decode($json, true, 8) ?? [];
        $return = [];

        foreach ($matrix as $definition) {
            if (!isset($definition['conditions']) || !is_array(($definedConditions = $definition['conditions']))) {
                continue;
            }

            // calculate relative weight of this option using hierarchy points by walking through the conditions
            $totalPoints = 0;
            foreach (self::CONDITIONS as $condition => $points) {
                if ('unspecified' === $condition) {
                    continue;
                }
                // consider unspecified conditions as valid
                if (!isset($definedConditions[$condition])) {
                    $totalPoints += self::CONDITIONS['unspecified'];
                    continue;
                }
                switch ($condition) {
                    case 'maximum_weight':
                        if ((float) $conditions['weight'] <= (float) $definedConditions[$condition]) {
                            $totalPoints += $points;
                            continue 2;
                        }
                        break;
                    case 'country_part_of':
                        if (self::isCountryPartOf($conditions['country'], $definedConditions[$condition])) {
                            $totalPoints += $points;
                            continue 2;
                        }
                        break;
                    default:
                        if ($conditions[$condition] === $definedConditions[$condition]) {
                            $totalPoints += $points;
                            continue 2;
                        }
                }
                // when condition is not met, don’t count it
                continue 2;
            }

            $return[] = [
                'definition' => $definition,
                'points'     => $totalPoints,
            ];
        }

        if (0 === count($return)) {
            // when nothing matched, act as a flat rate using the provided shipping cost
            return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
        }

        // sort by points, highest first, then return the price from the first result
        usort($return, static function ($a, $b) {
            return $b['points'] <=> $a['points'];
        });

        //file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', "--conditions--\n" . var_export($conditions, true) . "\n" . "--WEIGHTED--\n" . var_export($return, true) . "\n", FILE_APPEND);

        return (float) $return[0]['definition']['price'];
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
                return !in_array($needle, CountryCodes::EU_COUNTRIES);
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
