<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Block\Version;

use Magento\Backend\Block\AbstractBlock;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;

class VersionTab extends AbstractBlock implements RendererInterface
{
    public function render(AbstractElement $element)
    {
        return $this->getLayout()
            ->createBlock('Magento\Framework\View\Element\Template')
            ->setTemplate('MyParcelNL_Magento::version.phtml')
            ->toHtml();
    }
}
