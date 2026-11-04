<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Block\DataProviders\Email\Shipment\TrackingUrl;
use MyParcelNL\Magento\Service\TrackTrace\LinkResolver;

/**
 * The shipment email lists every carrier's tracks. The bootstrap's empty stub of Magento's provider
 * has no getUrl(), so it is declared here first with one that marks the delegation.
 */
if (! class_exists(Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl::class, false)) {
    eval('namespace Magento\Sales\Block\DataProviders\Email\Shipment;
        class TrackingUrl { public function getUrl(\\Magento\\Sales\\Model\\Order\\Shipment\\Track $track): string { return "magento-popup"; } }');
}

function emailTrack(?string $carrierCode): Track
{
    $track = Mockery::mock(Track::class . ', ArrayAccess');
    $track->shouldReceive('offsetExists')->with('carrier_code')->andReturn(null !== $carrierCode);
    $track->shouldReceive('offsetGet')->with('carrier_code')->andReturn($carrierCode);

    return $track;
}

function resolverReturning(string $url): LinkResolver
{
    $resolver = Mockery::mock(LinkResolver::class);
    $resolver->shouldReceive('forTrack')->andReturn($url);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(LinkResolver::class)->andReturn($resolver);
    ObjectManager::setInstance($objectManager);

    return $resolver;
}

it('links a MyParcel track to the MyParcel portal', function (?string $carrierCode) {
    resolverReturning('https://portal.example/track');

    expect((new TrackingUrl())->getUrl(emailTrack($carrierCode)))->toBe('https://portal.example/track');
})->with([
    'our carrier code'                => ['myparcel'],
    'a track built before the column' => [null],
]);

it('hands another carrier\'s track to Magento\'s own provider', function () {
    resolverReturning('https://portal.example/track')->shouldNotReceive('forTrack');

    expect((new TrackingUrl())->getUrl(emailTrack('dhl')))->toBe('magento-popup');
});
