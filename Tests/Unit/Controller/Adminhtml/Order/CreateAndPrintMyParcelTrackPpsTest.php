<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use MyParcelNL\Magento\Controller\Adminhtml\Order\CreateAndPrintMyParcelTrack;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Config;

/**
 * The order grid's PPS export. The collection's constructor defaults are a mass action's, and
 * OrderShipmentOptions reads a carrier in them as the admin's override of the checkout carrier —
 * so the PPS branch has to run the request through setOptionsFromParameters() like every other
 * caller of setFulfilment() does, or the modal's choice is ignored and a default is exported.
 *
 * Skips parent::__construct(Action) and shadows getRequest(), as the ApiAccessToken controller
 * tests do, because the Backend Action chain is a test stub here.
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

function createPpsGridController(MagentoOrderCollection $collection): FakeCreateAndPrintMyParcelTrack
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->with('selected_ids')->andReturn('1,2');
    $request->shouldReceive('setParams');

    $magentoOrders = Mockery::mock(OrderCollection::class);
    $magentoOrders->shouldReceive('addAttributeToFilter')->with('entity_id', ['in' => ['1', '2']]);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION)->andReturn($magentoOrders);

    $config = createConfig();
    $config->shouldReceive('getExportMode')->andReturn(Config::EXPORT_MODE_PPS);

    $controller = new FakeCreateAndPrintMyParcelTrack($request, $objectManager);
    setPrivateProperty($controller, 'orderCollection', $collection);
    setPrivateProperty($controller, 'config', $config);

    return $controller;
}

it('reads the request options before a PPS export, as the shipment branch does', function () {
    $collection = Mockery::mock(MagentoOrderCollection::class);
    $collection->shouldReceive('setOrderCollection')->once()->andReturnSelf();
    $collection->shouldReceive('setOptionsFromParameters')->once()->ordered()->andReturnSelf();
    $collection->shouldReceive('setFulfilment')->once()->ordered()->andReturnSelf();

    $labels = createPpsGridController($collection)->runMassAction();

    expect($labels)->toBeNull();
});
