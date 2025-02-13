<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;

abstract class AbstractDefaultDropOffPoint extends Field
{
    /**
     * Path to template file in theme.
     *
     * @var string
     */
    protected $_template = 'MyParcelNL_Magento::default_drop_off_point.phtml';

    /**
     * @var \MyParcelNL\Sdk\Model\Consignment\DropOffPoint
     */
    private $dropOffPoint;

    /**
     * @throws \Exception
     */
    public function __construct(Context $context, Config $config, array $data = [])
    {
        parent::__construct($context, $data);

        $dropOffPoint = (new AccountSettings($config->getGeneralConfig('api/key')))->getDropOffPoint(CarrierFactory::createFromId($this->getCarrierId()));

        $this->dropOffPoint = $dropOffPoint;
    }

    /**
     * @return int
     */
    abstract public function getCarrierId(): int;

    /**
     * Retrieve HTML markup for given form element
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     *
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()
            ->unsCanUseWebsiteValue()
            ->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Retrieve element HTML markup
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     *
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * @return array
     */
    public function getDropOffPointDetails(): ?array
    {
        if (! $this->dropOffPoint) {
            return null;
        }

        $dropOffPoint = $this->dropOffPoint;

        return [
            'location_name' => $dropOffPoint->getLocationName(),
            'city' => $dropOffPoint->getCity(),
            'street' => $dropOffPoint->getStreet(),
            'number' => $dropOffPoint->getNumber(),
            'number_suffix' => $dropOffPoint->getNumberSuffix(),
            'postal_code' => $dropOffPoint->getPostalCode(),
        ];
    }

    /**
     * Get the url of the stylesheet
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCssUrl(): string
    {
        return $this->_assetRepo->createAsset('MyParcelNL_Magento::css/config/DropOffPoint/style.css')
            ->getUrl();
    }
}
