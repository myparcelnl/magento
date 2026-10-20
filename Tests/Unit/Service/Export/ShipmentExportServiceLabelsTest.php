<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;

/**
 * fetchLabelPdf() against canned label answers, one per chunk of 100 ids. A 401 is what the API
 * answers for a chunk holding one id the key does not own.
 *
 * @param Response[] $answers
 *
 * @return array{result: array, history: array}
 */
function fetchLabelsAnswering(int $ids, array $answers): array
{
    $http    = makeGuzzleWithHistory($answers);
    $service = makeExportService(['key-a' => ShipmentApiFactory::make('key-a')]);
    setPrivateProperty($service, 'labelHttpClient', $http['client']);

    $result = $service->fetchLabelPdf(['key-a' => range(1, $ids)]);

    return ['result' => $result, 'history' => $http['history']];
}

function labelPdfAnswer(int $pages): Response
{
    return new Response(200, ['Content-Type' => 'application/pdf'], makePdf($pages, [105, 148]));
}

function refusedLabelsAnswer(): Response
{
    return new Response(401, ['Content-Type' => 'application/json'], '{"errors":[{"status":401,"message":"Access denied"}]}');
}

beforeEach(function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();
});

it('keeps fetching the next chunks after one is refused', function () {
    $run = fetchLabelsAnswering(250, [refusedLabelsAnswer(), labelPdfAnswer(2), labelPdfAnswer(3)]);

    expect($run['history'])->toHaveCount(3)
        ->and(pageSizes($run['result']['pdf']))->toHaveCount(5)
        ->and($run['result']['errors'])->toHaveCount(1)
        ->and($run['result']['errors'][0])->toStartWith('100 labels could not be fetched.');
});

it('reports every refused chunk on its own', function () {
    $run = fetchLabelsAnswering(150, [refusedLabelsAnswer(), refusedLabelsAnswer()]);

    expect($run['history'])->toHaveCount(2)
        ->and($run['result']['pdf'])->toBe('')
        ->and($run['result']['errors'])->toHaveCount(2)
        ->and($run['result']['errors'][1])->toStartWith('50 labels could not be fetched.');
});

it('reports nothing when every chunk answers with a PDF', function () {
    $run = fetchLabelsAnswering(150, [labelPdfAnswer(1), labelPdfAnswer(1)]);

    expect($run['result']['errors'])->toBe([])
        ->and(pageSizes($run['result']['pdf']))->toHaveCount(2);
});
