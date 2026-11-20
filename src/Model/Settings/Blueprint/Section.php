<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

/**
 * A tab on the settings form. A group without fields is dropped, so no tab shows an empty heading.
 */
final class Section
{
    private string $id;

    private string $label;

    /** @var Group[] */
    private array $groups;

    /** @param Group[] $groups */
    public function __construct(string $id, string $label, array $groups)
    {
        $this->id     = $id;
        $this->label  = $label;
        $this->groups = array_values(array_filter($groups, static function (Group $group): bool {
            return ! $group->isEmpty();
        }));
    }

    /** @return Group[] */
    public function groups(): array
    {
        return $this->groups;
    }

    /** A copy without the fields the form hides at this scope, and without a group left empty. */
    public function shownAt(string $scopeName): self
    {
        return new self($this->id, $this->label, array_map(static function (Group $group) use ($scopeName): Group {
            return $group->shownAt($scopeName);
        }, $this->groups));
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'label'         => $this->label,
            'showInDefault' => true,
            'showInWebsite' => true,
            'showInStore'   => true,
            'groups'        => array_map(static function (Group $group): array {
                return $group->toArray();
            }, $this->groups),
        ];
    }
}
