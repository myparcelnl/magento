<?php

declare(strict_types=1);

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\View\LayoutInterface;
use MyParcelNL\Magento\Block\Adminhtml\DynamicSettings;
use MyParcelNL\Magento\Block\System\Config\Form\NoteProviderInterface;
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

/**
 * DynamicSettings whose layout answers createBlock() with the given callback.
 *
 * A partial mock, because the test bootstrap stubs Magento\Backend\Block\Template empty, so the
 * real getLayout() does not exist here.
 *
 * @return DynamicSettings|Mockery\MockInterface
 */
function dynamicSettingsCreating(callable $createBlock): DynamicSettings
{
    $layout = Mockery::mock(LayoutInterface::class);
    $layout->shouldReceive('createBlock')->andReturnUsing($createBlock);

    $settings = Mockery::mock(DynamicSettings::class)->makePartial();
    $settings->shouldReceive('getLayout')->andReturn($layout);

    return $settings;
}

it('renders the note of the note model, and hands it the field', function () {
    $field = ['id' => 'weight_indication', 'note_model' => WeightUnitNote::class];

    $note = Mockery::mock(NoteProviderInterface::class);
    $note->shouldReceive('setData')->once()->with('field', $field);
    $note->shouldReceive('getNote')->andReturn('the computed note');

    $settings = dynamicSettingsCreating(static fn(string $class) => WeightUnitNote::class === $class ? $note : null);

    expect($settings->getNoteFor($field))->toBe('the computed note');
});

it('renders no note when the note model is not a note provider', function () {
    // It has a working getNote(), so only the NoteProviderInterface check can give ''.
    $block = new class extends Magento\Framework\DataObject {
        public function getNote(): string
        {
            return 'a note from a block that is not a note provider';
        }
    };

    $settings = dynamicSettingsCreating(static fn() => $block);

    expect($settings->getNoteFor(['note_model' => 'Some\\Other\\Block']))->toBe('');
});

it('renders no note, and keeps the settings screen up, when the note model fails', function () {
    $settings = dynamicSettingsCreating(static function () {
        throw new RuntimeException('no such block');
    });

    expect($settings->getNoteFor(['note_model' => WeightUnitNote::class]))->toBe('');
});
