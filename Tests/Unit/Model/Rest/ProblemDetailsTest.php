<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\ProblemDetails;

it('maps the proxy status codes to RFC 9110 titles', function () {
    expect(ProblemDetails::titleForStatus(405))->toBe('Method Not Allowed');
    expect(ProblemDetails::titleForStatus(413))->toBe('Content Too Large');
    expect(ProblemDetails::titleForStatus(502))->toBe('Bad Gateway');
});

it('still maps the pre-existing status codes correctly', function () {
    expect(ProblemDetails::titleForStatus(400))->toBe('Invalid Request');
    expect(ProblemDetails::titleForStatus(401))->toBe('Unauthorized');
    expect(ProblemDetails::titleForStatus(403))->toBe('Forbidden');
    expect(ProblemDetails::titleForStatus(404))->toBe('Not Found');
    expect(ProblemDetails::titleForStatus(406))->toBe('Unsupported API Version');
    expect(ProblemDetails::titleForStatus(409))->toBe('Incompatible Version Headers');
    expect(ProblemDetails::titleForStatus(500))->toBe('Internal Server Error');
});

it('falls back to "Error" for unmapped status codes', function () {
    expect(ProblemDetails::titleForStatus(418))->toBe('Error');
    expect(ProblemDetails::titleForStatus(0))->toBe('Error');
});

it('uses the mapped title when constructing via fromStatus', function () {
    $problem = ProblemDetails::fromStatus(413, 'too big');

    $payload = $problem->jsonSerialize();
    expect($payload['status'])->toBe(413);
    expect($payload['title'])->toBe('Content Too Large');
    expect($payload['detail'])->toBe('too big');
    expect($payload['type'])->toBeNull();
});

it('serialises to JSON with the new status codes intact', function () {
    $json = ProblemDetails::fromStatus(502, 'upstream unreachable')->toJsonString();
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['status'])->toBe(502);
    expect($decoded['title'])->toBe('Bad Gateway');
    expect($decoded['detail'])->toBe('upstream unreachable');
});
