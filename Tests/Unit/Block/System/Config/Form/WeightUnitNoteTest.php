<?php

declare(strict_types=1);

use Magento\Eav\Model\Config as EavConfig;
use MyParcelNL\Magento\Block\Adminhtml\DynamicSettings;
use MyParcelNL\Magento\Block\System\Config\Form\WeightUnitNote;

/**
 * @param null|bool $isScopeGlobal null means the attribute could not be read at all
 */
function weightUnitNoteFor(?bool $isScopeGlobal, bool $throws = false): WeightUnitNote
{
    $eavConfig = Mockery::mock(EavConfig::class);

    if ($throws) {
        $eavConfig->shouldReceive('getAttribute')->andThrow(new RuntimeException('no eav here'));
    } elseif (null === $isScopeGlobal) {
        $eavConfig->shouldReceive('getAttribute')->andReturn(null);
    } else {
        $attribute = Mockery::mock(Magento\Catalog\Model\ResourceModel\Eav\Attribute::class);
        $attribute->shouldReceive('isScopeGlobal')->andReturn($isScopeGlobal);
        $eavConfig->shouldReceive('getAttribute')->andReturn($attribute);
    }

    $block = newInstanceWithoutConstructor(WeightUnitNote::class);
    setPrivateProperty($block, 'eavConfig', $eavConfig);

    return $block;
}

it('explains why the setting has no scope', function () {
    expect(weightUnitNoteFor(true)->getNote())->toContain('default scope only');
});

it('says nothing more when product weight is global, which is the ordinary install', function () {
    expect(weightUnitNoteFor(true)->getNote())->not->toContain('Careful');
});

it('warns when product weight is not global, because then the one unit is an assumption', function () {
    expect(weightUnitNoteFor(false)->getNote())->toContain('Careful');
});

it('raises no false alarm when the attribute cannot be read', function () {
    expect(weightUnitNoteFor(null)->getNote())->not->toContain('Careful')
        ->and(weightUnitNoteFor(null, true)->getNote())->not->toContain('Careful');
});

it('asks for no note on a field that declares no note model', function () {
    $settings = newInstanceWithoutConstructor(DynamicSettings::class);

    expect($settings->getNoteFor(['id' => 'paper_type']))->toBe('');
});
