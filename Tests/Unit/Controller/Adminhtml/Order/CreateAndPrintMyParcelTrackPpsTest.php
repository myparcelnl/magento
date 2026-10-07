<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use MyParcelNL\Magento\Controller\Adminhtml\Order\CreateAndPrintMyParcelTrack;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;

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

    /** @var ManagerInterface Idem. */
    protected $messageManager;

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

/**
 * @param array<int, bool> $orderV1ByStore the selected orders' stores and whether each account has order v1
 */
function createPpsGridController(
    MagentoOrderCollection $collection,
    array                  $orderV1ByStore = [1 => true],
    ?ManagerInterface      $messages = null
): FakeCreateAndPrintMyParcelTrack
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->with('selected_ids')->andReturn('1,2');
    $request->shouldReceive('setParams');

    $magentoOrders = Mockery::mock(OrderCollection::class);
    $magentoOrders->shouldReceive('addAttributeToFilter')->with('entity_id', ['in' => ['1', '2']]);
    $magentoOrders->shouldReceive('getColumnValues')->with('store_id')->andReturn(array_map('strval', array_keys($orderV1ByStore)));

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(MagentoOrderCollection::PATH_MODEL_ORDER_COLLECTION)->andReturn($magentoOrders);

    $storedAccount = Mockery::mock(StoredAccount::class);
    $storedAccount->shouldReceive('hasOrderV1ForStore')->andReturnUsing(
        static fn(int $storeId): bool => $orderV1ByStore[$storeId]
    );

    $controller = new FakeCreateAndPrintMyParcelTrack($request, $objectManager);
    setPrivateProperty($controller, 'orderCollection', $collection);
    setPrivateProperty($controller, 'storedAccount', $storedAccount);
    setPrivateProperty($controller, 'messageManager', $messages ?? Mockery::mock(ManagerInterface::class));

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

it('refuses a selection that mixes order v1 accounts and others, and exports nothing', function () {
    $collection = Mockery::mock(MagentoOrderCollection::class);
    $collection->shouldReceive('setOrderCollection')->once()->andReturnSelf();
    $collection->shouldNotReceive('setOptionsFromParameters', 'setFulfilment', 'setNewMagentoShipment');

    $messages = Mockery::mock(ManagerInterface::class);
    $messages->shouldReceive('addErrorMessage')->once();

    expect(createPpsGridController($collection, [1 => true, 2 => false], $messages)->runMassAction())->toBeNull();
});
