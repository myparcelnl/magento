<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use InvalidArgumentException;

/**
 * A stored package or delivery type, plus whatever it resolved to.
 *
 * A stored type may be one we do not know: it comes from the checkout widget or an old order, and
 * which types exist belongs to the merchant's account. So nothing here substitutes a default — each
 * accessor says what it answers for an unresolved value.
 *
 * It is a class rather than a union return type because PHP 7.4 has no union types.
 */
final class TypeValue
{
    /** @var string|int|null exactly what was stored; null means nothing was */
    private $raw;

    /** @var int|null the resolved id, null when the raw value resolves to nothing we know */
    private $id;

    /** @var class-string a map class using MapsNamesToIds, kept per instance so name() can read it */
    private $mapClass;

    /** @param string|int|null $raw */
    private function __construct($raw, ?int $id, string $mapClass)
    {
        $this->raw      = $raw;
        $this->id       = $id;
        $this->mapClass = $mapClass;
    }

    /**
     * A numeric value is read as an id, because that is what the admin form posts. Anything else is
     * read as a name.
     *
     * @param string|int|null $raw
     * @param class-string    $mapClass PackageType or DeliveryType
     */
    public static function fromStored($raw, string $mapClass): self
    {
        if (null === $raw || '' === $raw) {
            return new self(null, null, $mapClass);
        }

        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $id = (int) $raw;

            return new self($raw, null === $mapClass::nameFromIdOrNull($id) ? null : $id, $mapClass);
        }

        if (! is_string($raw)) {
            // Out of contract. Accepting it left name() reading the value as a name while
            // toApiValue() re-tested it as an id, so one instance could answer both ways.
            throw new InvalidArgumentException(
                sprintf('A stored %s must be a string, an int or null, %s given', $mapClass::TYPE_LABEL, gettype($raw))
            );
        }

        return new self($raw, $mapClass::toIdOrNull($raw), $mapClass);
    }

    /** Nothing was stored — not the same as a stored value we cannot resolve. */
    public function isAbsent(): bool
    {
        return null === $this->raw;
    }

    /** The module name when resolved, the raw value verbatim when not, null when absent. */
    public function name(): ?string
    {
        if (null === $this->raw) {
            return null;
        }

        if (null !== $this->id) {
            $mapClass = $this->mapClass;

            return $mapClass::nameFromIdOrNull($this->id);
        }

        return (string) $this->raw;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * An unresolved *id* is passed through, because the API is what decides whether it exists. An
     * unresolved *name* cannot be sent at all, so it throws rather than being replaced.
     *
     * @throws \InvalidArgumentException
     */
    public function toApiValue(): int
    {
        if (null !== $this->id) {
            return $this->id;
        }

        $mapClass = $this->mapClass;

        if (null === $this->raw) {
            throw new InvalidArgumentException(
                sprintf('No %s was stored, so there is nothing to send', $mapClass::TYPE_LABEL)
            );
        }

        if (is_int($this->raw) || ctype_digit((string) $this->raw)) {
            return (int) $this->raw;
        }

        throw new InvalidArgumentException(
            sprintf('Unknown %s "%s" cannot be sent to the API', $mapClass::TYPE_LABEL, (string) $this->raw)
        );
    }
}
