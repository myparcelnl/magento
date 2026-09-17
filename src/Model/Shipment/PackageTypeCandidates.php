<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use InvalidArgumentException;

/**
 * The package types one carrier may still ship a given cart as, after capabilities and the options
 * the order forces have both had their say.
 *
 * Immutable, and it holds no weights: a candidate's weight is per-carrier configuration, read at a
 * different moment and needed outside this decision as well.
 *
 * PACKAGE_NAME is rejected rather than accepted. It is the fallback every cart falls back to, never
 * something capabilities rules in, and letting it be added would make "no candidates" ambiguous.
 */
final class PackageTypeCandidates
{
    /** @var string[] */
    private array $names;

    /** @param string[] $names */
    private function __construct(array $names)
    {
        $this->names = $names;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(string $packageTypeName): self
    {
        if (PackageType::PACKAGE_NAME === $packageTypeName) {
            throw new InvalidArgumentException('package is the fallback, never a candidate');
        }

        if (in_array($packageTypeName, $this->names, true)) {
            return $this;
        }

        return new self(array_merge($this->names, [$packageTypeName]));
    }

    public function has(string $packageTypeName): bool
    {
        return in_array($packageTypeName, $this->names, true);
    }

    /** @return string[] */
    public function names(): array
    {
        return $this->names;
    }
}
