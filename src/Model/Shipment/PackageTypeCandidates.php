<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use InvalidArgumentException;

/**
 * The package types one carrier may still ship a cart as, after the capabilities, the forced
 * options and the carrier's config toggles. Immutable, and without weights.
 *
 * PACKAGE_NAME is refused: it is the fallback for every cart, never a candidate.
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

        return new self(array_merge($this->names, [$packageTypeName]));
    }

    public function has(string $packageTypeName): bool
    {
        return in_array($packageTypeName, $this->names, true);
    }
}
