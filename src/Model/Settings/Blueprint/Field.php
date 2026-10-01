<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

/**
 * One setting on the generated settings form, serialised to the array dynamic_settings.phtml reads.
 *
 * Immutable: every with*() returns a copy. A dependency holds the Field it points at, never a path
 * string, so it cannot point at a field that does not exist. The default is not part of the form
 * array: the GeneratedDefaults config source reads it, so it acts as the field's config.xml value.
 */
final class Field
{
    public const TYPE_SELECT   = 'select';
    public const TYPE_TEXT     = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_TIME     = 'time';
    public const TYPE_BUTTON   = 'button';

    /** The optional keys, in the order toArray() emits them. */
    private const ATTRIBUTES = ['tooltip', 'comment', 'source_model', 'frontend_model', 'validate', 'note_model'];

    private string $path;

    private string $type;

    private string $label;

    /** @var array<string, string> */
    private array $attributes = [];

    /** @var array<int, array{0: Field, 1: string}> */
    private array $depends = [];

    private bool $defaultScopeOnly = false;

    private bool $disabled = false;

    private ?string $default = null;

    private function __construct(string $path, string $type, string $label)
    {
        $this->path  = $path;
        $this->type  = $type;
        $this->label = $label;
    }

    public static function select(string $path, string $label, string $sourceModel): self
    {
        return (new self($path, self::TYPE_SELECT, $label))->with('source_model', $sourceModel);
    }

    public static function text(string $path, string $label): self
    {
        return new self($path, self::TYPE_TEXT, $label);
    }

    public static function textarea(string $path, string $label): self
    {
        return new self($path, self::TYPE_TEXTAREA, $label);
    }

    public static function time(string $path, string $label): self
    {
        return new self($path, self::TYPE_TIME, $label);
    }

    public static function button(string $path, string $label, string $frontendModel): self
    {
        return (new self($path, self::TYPE_BUTTON, $label))->with('frontend_model', $frontendModel);
    }

    public function withTooltip(string $tooltip): self
    {
        return $this->with('tooltip', $tooltip);
    }

    public function withComment(string $comment): self
    {
        return $this->with('comment', $comment);
    }

    public function withValidate(string $validate): self
    {
        return $this->with('validate', $validate);
    }

    public function withFrontendModel(string $frontendModel): self
    {
        return $this->with('frontend_model', $frontendModel);
    }

    public function withNoteModel(string $noteModel): self
    {
        return $this->with('note_model', $noteModel);
    }

    /** What the path answers until a merchant saves it. */
    public function withDefault(string $default): self
    {
        $copy          = clone $this;
        $copy->default = $default;

        return $copy;
    }

    /** Shown only while $field holds $value. */
    public function dependsOn(Field $field, string $value = '1'): self
    {
        $copy            = clone $this;
        $copy->depends[] = [$field, $value];

        return $copy;
    }

    public function inDefaultScopeOnly(): self
    {
        $copy                   = clone $this;
        $copy->defaultScopeOnly = true;

        return $copy;
    }

    /** Rendered, but the merchant cannot change it. */
    public function asDisabled(): self
    {
        $copy           = clone $this;
        $copy->disabled = true;

        return $copy;
    }

    /** Whether the form shows this field at a scope: 'default', 'websites' or 'stores'. */
    public function isShownAt(string $scopeName): bool
    {
        return 'default' === $scopeName || ! $this->defaultScopeOnly;
    }

    public function default(): ?string
    {
        return $this->default;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The last path segment, which is what the form uses to name the row. */
    public function id(): string
    {
        $slash = strrpos($this->path, '/');

        return false === $slash ? $this->path : substr($this->path, $slash + 1);
    }

    public function toArray(): array
    {
        $field = [
            'id'            => $this->id(),
            'path'          => $this->path,
            'type'          => $this->type,
            'label'         => $this->label,
            'showInDefault' => true,
            'showInWebsite' => ! $this->defaultScopeOnly,
            'showInStore'   => ! $this->defaultScopeOnly,
        ];

        foreach (self::ATTRIBUTES as $key) {
            if (isset($this->attributes[$key])) {
                $field[$key] = $this->attributes[$key];
            }
        }

        if ([] !== $this->depends) {
            $field['depends'] = array_map(static function (array $dependency): array {
                return ['field' => $dependency[0]->path(), 'value' => $dependency[1]];
            }, $this->depends);
        }

        if ($this->disabled) {
            $field['disabled'] = true;
        }

        return $field;
    }

    private function with(string $key, string $value): self
    {
        $copy                   = clone $this;
        $copy->attributes[$key] = $value;

        return $copy;
    }
}
