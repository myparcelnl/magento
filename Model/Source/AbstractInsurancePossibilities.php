<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use Exception;
use Magento\Framework\Data\OptionSourceInterface;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

abstract class AbstractInsurancePossibilities implements OptionSourceInterface
{

    protected string $type;
    protected AbstractConsignment $carrier;

    /**
     * @throws Exception
     */
    public function __construct($carrierName, $type)
    {
        $this->type = $type;
        $this->carrier = consignmentFactory::createByCarrierName($carrierName);
    }

    /**
     * @return array
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
     */
    public function toArray(): array
    {
        $cc = null;
        if ($this->type === 'local') {
            $cc = $this->carrier->getLocalCountryCode();
        }
        if ($this->type === AbstractConsignment::CC_BE) {
            $cc = AbstractConsignment::CC_BE;
        }

        return array_reduce($this->getInsurancePossibilitiesArray($cc), static function ($array, $insuranceValue) {
            $array[$insuranceValue] = $insuranceValue;

            return $array;
        }, [0]);
    }

    /**
     * @param string|null $cc
     * @return array
     */
    abstract protected function getInsurancePossibilitiesArray(?string $cc = null): array;
}
