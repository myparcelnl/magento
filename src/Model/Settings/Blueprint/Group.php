<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

/**
 * A fieldset on the settings form: a heading, an optional note and its fields, in order.
 */
final class Group
{
    private string $id;

    private string $label;

    private ?string $comment;

    /** @var Field[] */
    private array $fields;

    /** @param Field[] $fields */
    public function __construct(string $id, string $label, array $fields, ?string $comment = null)
    {
        $this->id      = $id;
        $this->label   = $label;
        $this->fields  = array_values($fields);
        $this->comment = $comment;
    }

    /** @return Field[] */
    public function fields(): array
    {
        return $this->fields;
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'label'         => $this->label,
            'comment'       => $this->comment,
            'showInDefault' => true,
            'showInWebsite' => true,
            'showInStore'   => true,
            'fields'        => array_map(static function (Field $field): array {
                return $field->toArray();
            }, $this->fields),
        ];
    }
}
