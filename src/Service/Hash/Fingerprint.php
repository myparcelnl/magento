<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Hash;

/**
 * Derives a stable, non-reversible identifier from an arbitrary string — a config path suffix, a cache
 * id — wherever a value must act as a lookup key somewhere that should not hold the value itself.
 * Deliberately knows nothing about what it fingerprints.
 *
 * The output is a storage format: it is the lookup key for values already stored, so changing the
 * algorithm orphans every one of them. Treat a change to `of()` as a data migration.
 */
class Fingerprint
{
    /**
     * Characters to keep when using a fingerprint as a log label — enough to correlate lines without
     * printing a full digest on each one.
     */
    public const LABEL_LENGTH = 12;

    /**
     * 64 lowercase hex characters.
     */
    public function of(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Tells an already-fingerprinted value from a raw one, when reading back a mixed set of stored
     * keys or deciding whether a value is safe to log verbatim.
     */
    public function isFingerprint(string $value): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $value);
    }
}
