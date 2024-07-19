<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MyParcelBE\Magento\Helper\Data;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;

abstract class AbstractDefaultDropOffPoint extends Field
{
    /**
     * Path to template file in theme.
     *
     * @var string
     */
    protected $_template = 'MyParcelBE_Magento::default_drop_off_point.phtml';

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
        $helper             = $objectManager->get(Data::class);
        $dropOffPoint       = $helper->getDropOffPoint(CarrierFactory::createFromId($this->getCarrierId()));
        $this->dropOffPoint = $dropOffPoint;
        parent::__construct($context, $data);
    }

    /**
     * @return int
     */
    abstract public function getCarrierId(): int;

    /**
     * Retrieve HTML markup for given form element
     *
     * @param  \Magento\Framework\Data\Form\Element\AbstractElement $element
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
     * @param  \Magento\Framework\Data\Form\Element\AbstractElement $element
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
        return $this->dropOffPoint ? [
            'location_name' => $this->dropOffPoint->getLocationName(),
            'city'          => $this->dropOffPoint->getCity(),
            'street'        => $this->dropOffPoint->getStreet(),
            'number'        => $this->dropOffPoint->getNumber(),
            'number_suffix' => $this->dropOffPoint->getNumberSuffix(),
            'postal_code'   => $this->dropOffPoint->getPostalCode(),
        ] : null;
    }

    /**
     * Get the url of the stylesheet
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCssUrl(): string
    {
        return $this->_assetRepo->createAsset('MyParcelBE_Magento::css/config/DropOffPoint/style.css')
            ->getUrl();
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getButtonHtml(): string
    {
        $button = $this->getLayout()
            ->createBlock(Button::class)
            ->setData([
                'id'    => 'settings-button',
                'label' => __('Import'),
            ]);
        return $button->toHtml();
    }
}
