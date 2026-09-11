<?php

declare(strict_types=1);

/**
 * The API refuses a chunk as a whole, and reports every faulty shipment in that one response. The
 * service uses that: it excludes the orders the response named and re-sends the rest once, so a bad
 * order costs itself rather than its whole chunk.
 *
 * The bodies below are RFC 9457 Problem Details, which is what the API actually sends — not the
 * `common_responses_user_error` shape the Core API spec documents. The summary is `detail`, each
 * error carries `detail` and an `instance` JSON Pointer, and `errors` is a plain list. The generated
 * model declares neither `detail` nor `instance`, so none of this works through it.
 */
beforeEach(function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault()->shouldReceive('warning')->byDefault();
});

/** The rejection captured from acceptance on 2026-08-28, blaming shipment 0. */
function acceptanceRejectionBody(): array
{
    return [
        'type'       => 'urn:problem:invalid-shipments',
        'title'      => 'Invalid shipments',
        'status'     => 422,
        'detail'     => 'Verzending validatiefout',
        'instance'   => '/shipments',
        'request_id' => '1787924095.70556a918e7fac3d7',
        'errors'     => [
            [
                'type'     => 'urn:problem:invalid-postal-code',
                'title'    => 'Invalid postal code',
                'detail'   => "postal_code 'OPA' doesn't look like a correct postal code for country NL",
                'instance' => '/data/shipments/0/recipient/postal_code',
            ],
            [
                'type'     => 'urn:problem:invalid-recipient-phone-number',
                'title'    => 'Invalid recipient phone number',
                'detail'   => "Phone number '0900 90909090' is not valid",
                'instance' => '/data/shipments/0/recipient/phone',
            ],
        ],
    ];
}

it('re-sends the chunk without the refused order, so only that order fails', function () {
    $calls   = [];
    $service = makeExportService(['key' => makeRejectOnceShipmentApi(acceptanceRejectionBody(), $calls)]);

    $built = [
        builtShipmentFor('key', '3000000033'),
        builtShipmentFor('key', '000000116'),
        builtShipmentFor('key', '000000117'),
    ];

    $report = $service->createConcepts($built);

    expect($calls)->toHaveCount(2)
        ->and($calls[0])->toBe(['3000000033-1', '000000116-1', '000000117-1'])
        ->and($calls[1])->toBe(['000000116-1', '000000117-1'])
        ->and($report->succeeded())->toHaveKeys(['000000116', '000000117'])
        ->and(array_map('strval', array_keys($report->failed())))->toBe(['3000000033'])
        ->and($report->collateral())->toBeEmpty();
});

it('keeps every reason the response gave for the refused order', function () {
    // One shipment broke two rules. Showing only the first would cost a second fix-and-retry for
    // something the API had already said.
    $calls   = [];
    $service = makeExportService(['key' => makeRejectOnceShipmentApi(acceptanceRejectionBody(), $calls)]);

    $report = $service->createConcepts([
        builtShipmentFor('key', '3000000033'),
        builtShipmentFor('key', '000000116'),
    ]);

    $offender = $report->failureReasons()['3000000033'];

    expect($offender)->toHaveCount(2)
        ->and($offender[0])->toContain("postal_code 'OPA' doesn't look like a correct postal code")
        ->and($offender[0])->toContain('recipient.postal_code')
        ->and($offender[1])->toContain("Phone number '0900 90909090' is not valid")
        ->and($offender[1])->toContain('recipient.phone');
});

it('reports one line for the refused order and nothing at all for the rest', function () {
    $calls   = [];
    $service = makeExportService(['key' => makeRejectOnceShipmentApi(acceptanceRejectionBody(), $calls)]);

    $report = $service->createConcepts([
        builtShipmentFor('key', '3000000033'),
        builtShipmentFor('key', '000000116'),
        builtShipmentFor('key', '000000117'),
    ]);

    expect($report->failureMessages())->toHaveCount(1)
        ->and($report->failureMessages()[0])->toStartWith('3000000033: Invalid postal code');
});

it('retries once only, and reports every order that still did not ship', function () {
    // Not expected in practice: the first response should name every faulty shipment. If a second
    // rejection happens anyway, it is reported rather than retried again.
    $calls   = [];
    $service = makeExportService(['key' => makeRejectingShipmentApi(acceptanceRejectionBody(), $calls)]);

    $report = $service->createConcepts([
        builtShipmentFor('key', '3000000033'),
        builtShipmentFor('key', '000000116'),
        builtShipmentFor('key', '000000117'),
    ]);

    // The second rejection blames the first shipment of the *retry*, so that order is named too and
    // only the one nobody ever blamed is left as collateral.
    expect($calls)->toHaveCount(2)
        ->and(array_map('strval', array_keys($report->failed())))->toBe(['3000000033', '000000116'])
        ->and($report->collateral())->toBe(['000000117'])
        ->and($report->failureMessages())->toHaveCount(3)
        ->and($report->failureMessages()[2])
        ->toBe('1 other order in this batch was not exported. Correct the orders above and run the action again.');
});

it('does not retry when the response blames nobody', function () {
    $calls   = [];
    $body    = ['detail' => 'Verzending validatiefout', 'errors' => [['title' => 'Account problem', 'detail' => 'your account is not active']]];
    $service = makeExportService(['key' => makeRejectingShipmentApi($body, $calls)]);

    $report = $service->createConcepts([
        builtShipmentFor('key', '000000116'),
        builtShipmentFor('key', '000000117'),
    ]);

    expect($calls)->toHaveCount(1)
        ->and($report->failed())->toBeEmpty()
        ->and($report->collateral())->toBe(['000000116', '000000117'])
        ->and($report->failureMessages())->toBe([
            '2 orders were not exported. MyParcel refused this batch: Verzending validatiefout Account problem — your account is not active',
        ]);
});

it('does not retry a failure that may have created shipments', function () {
    // A 422 means the API validated and refused, so nothing exists upstream. A timeout does not say
    // that, and the API deduplicates nothing — re-sending could bill the merchant twice.
    $calls   = [];
    $service = makeExportService(['key' => makeTimingOutShipmentApi($calls)]);

    $report = $service->createConcepts([
        builtShipmentFor('key', '000000116'),
        builtShipmentFor('key', '000000117'),
    ]);

    expect($calls)->toHaveCount(1)
        ->and($report->collateral())->toBe(['000000116', '000000117']);
});

it('reads past the truncation in the exception message', function () {
    // Guzzle truncates its own message at ~120 characters; the detail lives in the response body.
    $calls = [];
    $body  = [
        'detail' => 'Verzending validatiefout',
        'errors' => [['message' => 'Country is required', 'instance' => '/data/shipments/0/recipient/cc']],
    ];

    $service = makeExportService(['key' => makeRejectOnceShipmentApi($body, $calls)]);

    $report = $service->createConcepts([
        builtShipmentFor('key', '000000114'),
        builtShipmentFor('key', '000000115'),
    ]);

    expect($report->failed()['000000114'])->toContain('Country is required')
        ->and($report->succeeded())->toHaveKey('000000115');
});
