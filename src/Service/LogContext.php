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

    /** A payload described for the log by its length and keys; its values carry a pickup address. */
    public static function shapeOf(?string $payload): string
    {
        if (null === $payload || '' === $payload) {
            return '(empty)';
        }

        $decoded = json_decode($payload, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            return sprintf('%d bytes, not JSON: %s', strlen($payload), json_last_error_msg());
        }

        if (! is_array($decoded)) {
            return sprintf('%d bytes, a JSON %s', strlen($payload), gettype($decoded));
        }

        return sprintf('%d bytes, keys: %s', strlen($payload), implode(',', array_keys($decoded)));
    }
}
