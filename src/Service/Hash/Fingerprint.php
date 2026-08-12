<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Hash;

/**
 * Derives a stable, non-reversible identifier from an arbitrary string.
 *
 * Deliberately knows nothing about what it fingerprints. It is used wherever a value must act as a
 * lookup key somewhere that should not hold the value itself — a config path suffix, a cache id —
 * whether because the value is a credential, or merely too long or unstable to use directly.
 *
 * The output is a storage format, not an implementation detail: it is the lookup key for values
 * already stored, so changing the algorithm orphans every one of them. Treat a change to `of()` as a
 * data migration.
 */
class Fingerprint
{
    /**
     * 64 lowercase hex characters.
     */
    public function of(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Whether $value has the shape of this class's own output.
     *
     * Tells an already-fingerprinted value from a raw one — needed when reading back a mixed set of
     * stored keys, and when deciding whether a value is safe to log verbatim.
     */
    public function isFingerprint(string $value): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $value);
    }
}
