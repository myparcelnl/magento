<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\LogContext;

function thrownHere(string $message = 'the api refused'): RuntimeException
{
    return new RuntimeException($message);
}

it('carries the exception and its frames', function () {
    $context = LogContext::of(thrownHere());

    expect($context['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($context['trace'])->toBeString()
        ->and($context['trace'])->toContain('thrownHere')
        ->and($context['trace'])->toContain('#0 ');
});

/**
 * The key is `error` and not the PSR-3 `exception` on purpose.
 *
 * Magento\Framework\Logger\Handler\System::write() diverts any record whose context has an
 * `exception` key to exception.log and returns, so naming it that way silently moves every failure
 * away from the notices that say what was being attempted. Renaming this key relocates the module's
 * whole log output, which is why it is pinned rather than left to taste.
 */
it('names the exception in a way that keeps the line in system.log', function () {
    $context = LogContext::of(thrownHere());

    expect($context)->toHaveKey('error')
        ->and($context)->not->toHaveKey('exception');
});

it('reads identifying data before the error', function () {
    // The trace is a multi-line block, so anything put after it is a long way down the line.
    $context = LogContext::of(thrownHere(), ['quote_id' => 7]);

    expect(array_keys($context))->toBe(['quote_id', 'error', 'trace'])
        ->and($context['quote_id'])->toBe(7);
});

it('keeps the error even when a caller names a key the same', function () {
    // The reserved keys win, so a slip cannot replace the exception with a string.
    $context = LogContext::of(thrownHere(), ['error' => 'not the exception']);

    expect($context['error'])->toBeInstanceOf(RuntimeException::class);
});
