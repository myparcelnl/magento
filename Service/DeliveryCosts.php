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

    private const AVAILABLE_CONDITIONS = [
        'country',
        'country_part_of',
        'package_type',
        'carrier_name',
        'maximum_weight',
    ];
    private const HIERARCHY_POINTS     = [
        'invalid'         => 0,
        'unspecified'     => 1,
        'country'         => 32,
        'country_part_of' => 16,
        'package_type'    => 8,
        'carrier_name'    => 4,
        'maximum_weight'  => 2,
    ];

    public function __construct(Weight $weight, Config $config)
    {
        $this->weight = $weight;
        $this->config = $config;
    }

    /**
     * @param Quote       $quote to get the weight and default carrier, package type and country
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

        // TODO make a Conditions class and use that to prevent arbitrary arrays
        return $this->calculate([
                                    'carrier_name' => $carrierName,
                                    'package_type' => $packageType,
                                    'country'      => $countryCode,
                                    'weight'       => $weight,
                                ]);
    }

    private function calculate(array $conditions): float
    {
        if (!isset($conditions['carrier_name'], $conditions['package_type'], $conditions['country'], $conditions['weight'])) {
            throw new \InvalidArgumentException('Missing required conditions');
        }

        $json = $this->config->getGeneralConfig('matrix/delivery_costs');
        if (null === $json) {
            return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
        }

        $matrix   = json_decode($json, true, 8) ?? [];
        $weighted = [];

        // TODO make a Conditions class and use that to prevent arbitrary arrays
        foreach ($matrix as $definition) {
            if (!isset($definition['conditions']) || !is_array(($definedConditions = $definition['conditions']))) {
                continue;
            }
            //file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', "--DEF--\n" . var_export($definition, true) . "\n", FILE_APPEND);

            // calculate relative weight of this option using hierarchy points by walking through the conditions
            $points = 0;
            foreach (self::AVAILABLE_CONDITIONS as $condition) {
                // consider unspecified conditions as valid
                if (!isset($definedConditions[$condition])) {
                    $points += self::HIERARCHY_POINTS['unspecified'];
                    continue;
                }
                switch ($condition) {
                    case 'maximum_weight':
                        if ((float) $conditions['weight'] <= (float) $definedConditions[$condition]) {
                            $points += self::HIERARCHY_POINTS[$condition];
                            continue 2;
                        }
                        break;
                    case 'country_part_of':
                        if (self::isCountryPartOf($conditions['country'], $definedConditions[$condition])) {
                            $points += self::HIERARCHY_POINTS[$condition];
                            continue 2;
                        }
                        break;
                    default:
                        if ($conditions[$condition] === $definedConditions[$condition]) {
                            $points += self::HIERARCHY_POINTS[$condition];
                            continue 2;
                        }
                }
                // no points were added by the switch, this means the condition is not met
                break 2;
            }
            $weighted[] = [
                'definition' => $definition,
                'points'     => $points,
            ];
        }

        if (0 === count($weighted)) {
            // when nothing matched, act as a flat rate using the provided shipping cost
            return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
        }

        // sort by points, highest first, and return the price from the first result
        usort($weighted, static function ($a, $b) {
            return $b['points'] <=> $a['points'];
        });

        //file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', "--WEIGHTED--\n" . var_export($weighted, true) . "\n", FILE_APPEND);

        return (float) $weighted[0]['definition']['price'];
    }

    static function isCountryPartOf(string $needle, $haystackDefinition): bool
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
