<?php

declare(strict_types=1);

it('fails open for a store with no api key rather than stopping the page', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/No MyParcel API key for store 7/'));

    $r      = makeCapabilitiesProvider();
    $lookup = capabilitiesLookupOver($r['provider'], '');

    expect($lookup->forRequest(7, 'NL')->isPermissive())->toBeTrue()
        ->and($lookup->forRequest(7, 'NL', 'package')->isPermissive())->toBeTrue()
        ->and($lookup->answeredPermissively())->toBeTrue()
        ->and($r['history'])->toHaveCount(0);
});

it('asks once for two stores that share one api key', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $r      = makeCapabilitiesProvider([new GuzzleHttp\Psr7\Response(200, [], capabilitiesBody([capabilityResult()]))]);
    $lookup = capabilitiesLookupOver($r['provider']);

    $lookup->forRequest(1, 'NL');
    $lookup->forRequest(2, 'NL');

    expect($r['history'])->toHaveCount(1)
        ->and($lookup->answeredPermissively())->toBeFalse();
});
