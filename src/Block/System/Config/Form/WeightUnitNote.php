<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Throwable;

/**
 * The note under the weight type setting, saying why it has no scope.
 *
 * Magento's `weight` product attribute is global, so one product carries one weight number for every
 * store, and a unit that differs per scope would make that number mean two things at once. The
 * setting is therefore offered at default scope only.
 *
 * A merchant or another extension can make `weight` scoped. That assumption then stops holding, and
 * this block says so where it bites rather than leaving it in a release note.
 *
 * Renders no control: `note_model` feeds the template's note slot and leaves the field itself alone.
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

    /**
     * True is also the answer when the attribute cannot be read at all, which is what the catch is
     * for. A note must never be the reason the settings screen fails, and warning about a problem we
     * could not confirm is worse than saying nothing.
     */
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
