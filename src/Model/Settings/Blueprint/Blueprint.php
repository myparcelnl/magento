<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

/**
 * The whole settings form for one scope.
 *
 * paths() is also the save allow-list: a path the form does not offer at a scope is not writable
 * there.
 */
final class Blueprint
{
    /** @var Section[] */
    private array $sections;

    private bool $permissive;

    /** @param Section[] $sections */
    public function __construct(array $sections, bool $permissive = false)
    {
        $this->sections   = array_values($sections);
        $this->permissive = $permissive;
    }

    /** True when the account's capabilities could not be read, so the form shows no carrier. */
    public function isPermissive(): bool
    {
        return $this->permissive;
    }

    /** The form at one scope: without the fields it hides there, so paths() is that scope's allow-list. */
    public function shownAt(string $scopeName): self
    {
        return new self(array_map(static function (Section $section) use ($scopeName): Section {
            return $section->shownAt($scopeName);
        }, $this->sections), $this->permissive);
    }

    /** @return string[] */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->sections as $section) {
            foreach ($section->groups() as $group) {
                foreach ($group->fields() as $field) {
                    $paths[] = $field->path();
                }
            }
        }

        return $paths;
    }

    /** @return array{sections: array} */
    public function toArray(): array
    {
        return [
            'sections' => array_map(static function (Section $section): array {
                return $section->toArray();
            }, $this->sections),
        ];
    }
}
