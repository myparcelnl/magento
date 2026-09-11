<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Throwable;

/**
 * An exception, described for the log.
 *
 * Not the PSR-3 `exception` key on purpose: Magento's system handler diverts any record carrying
 * that key to exception.log (Logger\Handler\System::write), away from the notices that explain what
 * was being attempted. The trace is a separate string because Handler\Base builds its LineFormatter
 * with includeStacktraces = false and so drops the frames of an exception in context.
 */
class LogContext
{
    /**
     * @param array $extra identifying data, read before the error; `error` and `trace` are reserved
     *                     and win, so a clash cannot replace the exception with a string
     */
    public static function of(Throwable $e, array $extra = []): array
    {
        return array_replace($extra, ['error' => $e, 'trace' => $e->getTraceAsString()]);
    }

    /**
     * A payload described for the log without being reproduced in it.
     *
     * Delivery options carry a pickup address, so the whole blob does not belong in a log file. The
     * first bytes plus the length are what a parse failure is actually diagnosed from.
     */
    public static function excerpt(?string $payload, int $keep = 200): string
    {
        if (null === $payload || '' === $payload) {
            return '(empty)';
        }

        $length = strlen($payload);

        return $length <= $keep
            ? $payload
            : substr($payload, 0, $keep) . sprintf('… (%d bytes total)', $length);
    }
}
