<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Block\Version;

use Magento\Backend\Block\AbstractBlock;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;
use Magento\Framework\View\Element\Template;

class VersionTab extends AbstractBlock implements RendererInterface
{
    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function render(AbstractElement $element): string
    {
        return $this->getLayout()
            ->createBlock(Template::class)
            ->setTemplate('MyParcelNL_Magento::version.phtml')
            ->toHtml();
    }
}
