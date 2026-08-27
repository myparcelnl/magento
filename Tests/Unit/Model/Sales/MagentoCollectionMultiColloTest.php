<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

// capabilityResult() and makeCapabilitiesRepository() live in Tests/Helpers/CapabilitiesFixtures.php.

/**
 * The constructor is skipped: it builds an SDK collection and half a dozen services, while
 * canUseMultiCollo() reads only the consignment and the repository off the object manager.
 *
 * @param \GuzzleHttp\Psr7\Response[] $responses
 */
function createCollectionAnswering(array $responses): array
{
    $repository = makeCapabilitiesRepository($responses);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')
        ->with(CapabilitiesRepository::class)
        ->andReturn($repository['repository']);

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);

    return ['collection' => $collection, 'history' => &$repository['history']];
}

/**
 * A real consignment, not a double: AbstractConsignment::getCarrierName() is final, so a Mockery
 * mock runs the real method and answers null for every carrier.
 */
function consignmentFor(string $carrier, ?string $country, ?int $packageType, ?string $apiKey): AbstractConsignment
{
    $consignment = ConsignmentFactory::createByCarrierName($carrier);

    if (null !== $country) {
        $consignment->setCountry($country);
    }
    if (null !== $packageType) {
        setPrivateProperty($consignment, 'package_type', $packageType);
    }
    if (null !== $apiKey) {
        $consignment->setApiKey($apiKey);
    }

    return $consignment;
}

function colloResponse(int $max): GuzzleHttp\Psr7\Response
{
    return new GuzzleHttp\Psr7\Response(200, [], capabilitiesBody([
        capabilityResult(['packageTypes' => ['PACKAGE'], 'collo' => ['max' => $max]]),
    ]));
}

it('allows multicollo when the account reports room for more than one collo', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $c = createCollectionAnswering([colloResponse(10)]);

    expect($c['collection']->canUseMultiCollo(
        consignmentFor(Carrier::POSTNL, 'NL', PackageType::PACKAGE, 'a-key')
    ))->toBeTrue();
});

it('refuses multicollo when the account reports a maximum of one', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    $c = createCollectionAnswering([colloResponse(1)]);

    expect($c['collection']->canUseMultiCollo(
        consignmentFor(Carrier::POSTNL, 'NL', PackageType::PACKAGE, 'a-key')
    ))->toBeFalse();
});

it('no longer hardcodes PostNL and NL or BE', function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault();

    // A carrier and a country the old rule rejected outright.
    $c = createCollectionAnswering([new GuzzleHttp\Psr7\Response(200, [], capabilitiesBody([
        capabilityResult(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE'], 'collo' => ['max' => 5]]),
    ]))]);

    expect($c['collection']->canUseMultiCollo(
        consignmentFor(Carrier::DPD, 'FR', PackageType::PACKAGE, 'a-key')
    ))->toBeTrue();
});

it('refuses rather than fails open when the maximum is unknown', function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    // Deliberately not the fail-open the rest of the layer does: this picks between two ways to
    // export, and separate consignments are the branch that always works.
    $c = createCollectionAnswering([new GuzzleHttp\Psr7\Response(500, [], '')]);

    expect($c['collection']->canUseMultiCollo(
        consignmentFor(Carrier::POSTNL, 'NL', PackageType::PACKAGE, 'a-key')
    ))->toBeFalse();
});

it('asks nothing when the consignment cannot say what it is', function () {
    // An empty response queue: any lookup would exhaust the handler and fail the test.
    $c = createCollectionAnswering([]);

    expect($c['collection']->canUseMultiCollo(consignmentFor(Carrier::POSTNL, null, PackageType::PACKAGE, 'a-key')))
        ->toBeFalse('no country')
        ->and($c['collection']->canUseMultiCollo(consignmentFor(Carrier::POSTNL, 'NL', 99, 'a-key')))
        ->toBeFalse('a package type with no name')
        ->and($c['collection']->canUseMultiCollo(consignmentFor(Carrier::POSTNL, 'NL', PackageType::PACKAGE, null)))
        ->toBeFalse('no api key')
        ->and($c['history'])->toHaveCount(0);
});
