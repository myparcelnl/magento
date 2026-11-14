<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Hash;

/**
 * Derives a stable, non-reversible lookup key (a config path suffix, a cache id) from a value that
 * must not be stored itself.
 *
 * The output is a storage format: changing the algorithm orphans every stored value. Treat a change
 * to `of()` as a data migration.
 */
class Fingerprint
{
    /** Characters to keep when a fingerprint is a log label: enough to correlate lines. */
    public const LABEL_LENGTH = 12;

    /** Get the fingerprint: 64 lowercase hex characters. */
    public function of(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Check whether a value is already a fingerprint, for example before it is logged verbatim. */
    public function isFingerprint(string $value): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $value);
    }
}
