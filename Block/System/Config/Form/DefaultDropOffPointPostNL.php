<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;

class DefaultDropOffPointPostNL extends DefaultDropOffPoint
{
    /**
     * @var \MyParcelNL\Sdk\src\Model\Consignment\DropOffPoint
     */
    private $dropOffPoint;

    /**
     * @throws \Exception
     */
    public function __construct(Context $context, array $data = [])
    {
        $objectManager      = ObjectManager::getInstance();
        $helper             = $objectManager->create(Data::class);
        $this->dropOffPoint = $helper->getDropOffPoint(CarrierFactory::createFromName(CarrierPostNL::NAME));
        parent::__construct($context, $data);
    }

    /**
     * @return array
     */
    public function getDropOffPointDetails(): array
    {
        return [
            'location_name' => $this->dropOffPoint->getLocationName(),
            'city'          => $this->dropOffPoint->getCity(),
            'street'        => $this->dropOffPoint->getStreet(),
            'number'        => $this->dropOffPoint->getNumber(),
            'number_suffix' => $this->dropOffPoint->getNumberSuffix(),
            'postal_code'   => $this->dropOffPoint->getPostalCode(),
        ];
    }

    public function getCarrierId(): string
    {
        return (string) CarrierPostNL::ID;
    }
}
