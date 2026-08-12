<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Hash\Fingerprint;

/**
 * These pin the algorithm on purpose. A failure here is not a broken test; it means everything stored
 * under the old algorithm has just been orphaned.
 */
it('produces 64 lowercase hex characters', function () {
    expect((new Fingerprint())->of('some-api-key'))->toMatch('/^[a-f0-9]{64}$/');
});

it('is deterministic', function () {
    $fingerprint = new Fingerprint();

    expect($fingerprint->of('same-input'))->toBe($fingerprint->of('same-input'));
});

it('is sha256, the documented algorithm', function () {
    expect((new Fingerprint())->of('some-api-key'))->toBe(hash('sha256', 'some-api-key'));
});

it('gives different inputs different fingerprints', function () {
    $fingerprint = new Fingerprint();

    expect($fingerprint->of('key-a'))->not->toBe($fingerprint->of('key-b'));
});

it('never returns the input it was given', function () {
    $apiKey = 'a-plaintext-api-key';

    expect((new Fingerprint())->of($apiKey))->not->toContain($apiKey);
});

it('handles an empty string without erroring', function () {
    expect((new Fingerprint())->of(''))->toMatch('/^[a-f0-9]{64}$/');
});

it('recognises its own output', function () {
    $fingerprint = new Fingerprint();

    expect($fingerprint->isFingerprint($fingerprint->of('anything')))->toBeTrue();
});

it('does not mistake a raw value for a fingerprint', function () {
    $fingerprint = new Fingerprint();

    expect($fingerprint->isFingerprint('a-plaintext-api-key'))->toBeFalse()
        ->and($fingerprint->isFingerprint(''))->toBeFalse()
        // Right length, but uppercase hex is not what of() emits.
        ->and($fingerprint->isFingerprint(strtoupper($fingerprint->of('anything'))))->toBeFalse()
        // Right alphabet, wrong length.
        ->and($fingerprint->isFingerprint(str_repeat('a', 63)))->toBeFalse();
});
