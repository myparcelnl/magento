<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class DefaultDropOffPoint extends Field
{
    /**
     * Path to template file in theme.
     *
     * @var string
     */
    protected $_template = 'MyParcelNL_Magento::default_drop_off_point.phtml';

    /**
     * @var \MyParcelNL\Sdk\src\Model\Consignment\DropOffPoint
     */
    private $dropOffPoint;

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

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

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getButtonHtml()
    {
        /**
         * @var \MyParcelNL\Sdk\src\Model\Consignment\DropOffPoint
         */
        $button = $this->getLayout()
            ->createBlock(Button::class)
            ->setData([
                'id'    => 'settings-button',
                'label' => __('Import'),
            ]);
        return $button->toHtml();
    }
}
