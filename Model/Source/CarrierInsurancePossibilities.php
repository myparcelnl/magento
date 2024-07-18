<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Exception;
use Magento\Framework\Data\OptionSourceInterface;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Services\CountryCodes;

class CarrierInsurancePossibilities implements OptionSourceInterface
{
    protected string $type;
    protected AbstractConsignment $carrier;

    /**
     * @param string $carrierName
     * @param string $type
     * @throws Exception
     */
    public function __construct(string $carrierName, string $type)
    {
        $this->type = $type;
        $this->carrier = ConsignmentFactory::createByCarrierName($carrierName);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function toOptionArray(): array
    {
        return array_map(static function ($value, $key) {
            return [
                'value' => $key,
                'label' => $value,
            ];
        }, $this->toArray(), array_keys($this->toArray()));
    }

    /**
     * @return array
     * @throws Exception
     */
    public function toArray(): array
    {
        $cc = $this->getCc();

        return array_reduce($this->getInsurancePossibilitiesArray($cc), static function ($array, $insuranceValue) {
            $array[$insuranceValue] = $insuranceValue;

            return $array;
        }, [0]);
    }

    /**
     * @throws Exception
     */
    protected function getInsurancePossibilitiesArray(?string $cc = null): array
    {
        return $this->carrier->getInsurancePossibilities($cc);
    }

    /**
     * @return string|null
     */
    private function getCc(): ?string
    {
        $cc = null;
        if ($this->type === 'local') {
            $cc = $this->carrier->getLocalCountryCode();
        }

        if ($this->type === AbstractConsignment::CC_BE) {
            $cc = AbstractConsignment::CC_BE;
        }

        if ($this->type === CountryCodes::ZONE_EU) {
            $cc = CountryCodes::ZONE_EU;
        }

        if ($this->type === CountryCodes::ZONE_ROW) {
            $cc = CountryCodes::ZONE_ROW;
        }

        return $cc;
    }
}
