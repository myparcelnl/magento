<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Type;

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
abstract class AbstractTypeValue
{
    /** @var string|int|null exactly what was stored; null means nothing was */
    private $raw;

    /** @var int|null the resolved id, null when the raw value resolves to nothing we know */
    private $id;

    /** @param string|int|null $raw */
    final protected function __construct($raw, ?int $id)
    {
        $this->raw = $raw;
        $this->id  = $id;
    }

    /**
     * A numeric value is read as an id, because that is what the admin form posts. Anything else is
     * read as a name.
     *
     * @param string|int|null $raw
     *
     * @return static
     */
    public static function fromStored($raw): self
    {
        if (null === $raw || '' === $raw) {
            return new static(null, null);
        }

        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $id = (int) $raw;

            return new static($raw, null === static::nameForId($id) ? null : $id);
        }

        if (! is_string($raw)) {
            return new static($raw, null);
        }

        return new static($raw, static::idForName($raw));
    }

    /** Nothing was stored — not the same as a stored value we cannot resolve. */
    public function isAbsent(): bool
    {
        return null === $this->raw;
    }

    public function isKnown(): bool
    {
        return null !== $this->id;
    }

    /** The module name when resolved, the raw value verbatim when not, null when absent. */
    public function name(): ?string
    {
        if (null === $this->raw) {
            return null;
        }

        if (null !== $this->id) {
            return static::nameForId($this->id);
        }

        return (string) $this->raw;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * An unresolved value renders as itself ('Package type 31'), so an admin sees what the order
     * carries rather than a plausible wrong answer. Not translated: wrap in __() at the output site.
     */
    public function label(): string
    {
        if (null === $this->raw) {
            return '';
        }

        if (null !== $this->id) {
            return (string) static::nameForId($this->id);
        }

        return static::typeLabel() . ' ' . (string) $this->raw;
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

        if (null === $this->raw) {
            throw new InvalidArgumentException(
                sprintf('No %s was stored, so there is nothing to send', strtolower(static::typeLabel()))
            );
        }

        if (is_int($this->raw) || ctype_digit((string) $this->raw)) {
            return (int) $this->raw;
        }

        throw new InvalidArgumentException(
            sprintf('Unknown %s "%s" cannot be sent to the API', strtolower(static::typeLabel()), (string) $this->raw)
        );
    }

    abstract protected static function nameForId(int $id): ?string;

    abstract protected static function idForName(string $name): ?int;

    /** Human-readable name of the type itself, for labelling an unresolved value. */
    abstract protected static function typeLabel(): string;
}
