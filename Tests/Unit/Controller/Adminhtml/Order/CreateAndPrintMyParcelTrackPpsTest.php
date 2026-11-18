<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use MyParcelNL\Magento\Controller\Adminhtml\Order\CreateAndPrintMyParcelTrack;
use MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

/**
 * The order grid's PPS export. The collection's constructor defaults are a mass action's, and
 * OrderShipmentOptions reads a carrier in them as the admin's override of the checkout carrier,
 * so the PPS branch has to run the request through setOptionsFromParameters() like every other
 * caller of setFulfilment() does, or the modal's choice is ignored and a default is exported.
 *
 * Skips parent::__construct(Action) and shadows getRequest(): the Backend Action chain is a stub here.
 */
final class FakeCreateAndPrintMyParcelTrack extends CreateAndPrintMyParcelTrack
{
    /** @var RequestInterface Shadows the parent declaration under the test stub autoloader. */
    protected $_request;

    /** @var ObjectManagerInterface Idem. */
    protected $_objectManager;

    public function __construct(RequestInterface $request, ObjectManagerInterface $objectManager)
    {
        $this->_request       = $request;
        $this->_objectManager = $objectManager;
    }

    public function getRequest()
    {
        return $this->_request;
    }

    public function runMassAction(): ?array
    {
        return $this->massAction();
    }
}

function createGridController(
    MagentoOrderCollection $collection,
    string $exportMode = Config::EXPORT_MODE_PPS,
    bool $trackEmailEnabled = true
): FakeCreateAndPrintMyParcelTrack {
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->with('selected_ids')->andReturn('1,2');
    $request->shouldReceive('setParams');

    $magentoOrders = Mockery::mock(OrderCollection::class);
    $magentoOrders->shouldReceive('addAttributeToFilter')->with('entity_id', ['in' => ['1', '2']]);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION)->andReturn($magentoOrders);

    $config = createConfig();
    $config->shouldReceive('getExportMode')->andReturn($exportMode);

    $trackSender = Mockery::mock(TrackSender::class);
    $trackSender->shouldReceive('isEnabled')->andReturn($trackEmailEnabled);

    $controller = new FakeCreateAndPrintMyParcelTrack($request, $objectManager);
    setPrivateProperty($controller, 'orderCollection', $collection);
    setPrivateProperty($controller, 'config', $config);
    setPrivateProperty($controller, 'trackSender', $trackSender);

    return $controller;
}

it('reads the request options before a PPS export, as the shipment branch does', function () {
    $collection = Mockery::mock(MagentoOrderCollection::class);
    $collection->shouldReceive('setOrderCollection')->once()->andReturnSelf();
    $collection->shouldReceive('setOptionsFromParameters')->once()->ordered()->andReturnSelf();
    $collection->shouldReceive('setFulfilment')->once()->ordered()->andReturnSelf();

    $labels = createGridController($collection)->runMassAction();

    expect($labels)->toBeNull();
});

/** @return bool what the export asked Magento: send its own shipment email or not */
function magentoShipmentMailFor(string $requestType, bool $trackEmailEnabled): bool
{
    $notify     = null;
    $collection = Mockery::mock(MagentoOrderCollection::class);
    $collection->shouldReceive('setOrderCollection')->andReturnSelf();
    $collection->shouldReceive('setOptionsFromParameters')->andReturnSelf();
    $collection->shouldReceive('getOption')->with('request_type')->andReturn($requestType);
    // Stops the run here: the rest of the export is not what these cases are about.
    $collection->shouldReceive('setNewMagentoShipment')->andReturnUsing(static function (bool $mail) use (&$notify) {
        $notify = $mail;

        throw new RuntimeException('stop');
    });

    expect(fn () => createGridController($collection, Config::EXPORT_MODE_SHIPMENTS, $trackEmailEnabled)->runMassAction())
        ->toThrow(RuntimeException::class, 'stop');

    return $notify;
}

it('leaves the shipment mail to the track & trace mail that follows the labels', function () {
    expect(magentoShipmentMailFor('download', true))->toBeFalse();
});

it('lets Magento mail the shipment when no track & trace mail follows', function () {
    expect(magentoShipmentMailFor('download', false))->toBeTrue()
        ->and(magentoShipmentMailFor(TrackAndTrace::VALUE_CONCEPT, true))->toBeTrue();
});
