<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Throwable;

/**
 * The note under the weight type setting, saying why it has default scope only.
 *
 * Magento's `weight` attribute is global, so a unit per scope would give one number two meanings.
 * When an installation makes `weight` scoped, the note warns about it. Renders no control.
 */
class WeightUnitNote extends Template implements NoteProviderInterface
{
    private EavConfig $eavConfig;

    public function __construct(Context $context, EavConfig $eavConfig, array $data = [])
    {
        parent::__construct($context, $data);
        $this->eavConfig = $eavConfig;
    }

    public function getNote(): string
    {
        $note = (string) __('MyParcel reads every product weight with this one unit, so this setting exists in default scope only.');

        if ($this->productWeightIsGlobal()) {
            return $note;
        }

        return $note . ' ' . __('Careful: product weight is not a global attribute in this installation, so a product can weigh a different number per scope. MyParcel still reads every one of them with the unit above.');
    }

    /** Check whether product weight is global; true when the attribute cannot be read, so the screen never fails. */
    private function productWeightIsGlobal(): bool
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'weight');

            return ! $attribute || $attribute->isScopeGlobal();
        } catch (Throwable $e) {
            return true;
        }
    }
}
