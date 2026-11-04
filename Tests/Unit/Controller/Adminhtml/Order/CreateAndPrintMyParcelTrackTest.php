<?php

declare(strict_types=1);

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MyParcelNL\Magento\Controller\Adminhtml\Order\CreateAndPrintMyParcelTrack;

/**
 * Skip parent::__construct(Action) to avoid building the order collection and the Backend Action context chain.
 */
final class FakeCreateAndPrintMyParcelTrack extends CreateAndPrintMyParcelTrack
{
    /** @var ObjectManagerInterface Shadow the parent declaration under the test stub autoloader. */
    protected $_objectManager;

    /** @var ManagerInterface Shadow the parent declaration under the test stub autoloader. */
    protected $messageManager;

    public function __construct(ObjectManagerInterface $objectManager, ManagerInterface $messageManager)
    {
        $this->_objectManager = $objectManager;
        $this->messageManager = $messageManager;
    }
}

/**
 * Runs the address filter for one order and returns the order ids it kept and the error messages it added.
 *
 * @return array{kept: array, errors: string[]}
 */
function filterOrderAddress(?string $postcode, string $countryId, array $street = ['Antareslaan 31']): array
{
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getStreet')->andReturn($street);
    $address->shouldReceive('getPostcode')->andReturn($postcode);
    $address->shouldReceive('getCountryId')->andReturn($countryId);

    $order = Mockery::mock(Order::class);
    $order->shouldReceive('load')->andReturnSelf();
    $order->shouldReceive('getShippingAddress')->andReturn($address);
    $order->shouldReceive('getIncrementId')->andReturn('000000001');

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Order::class)->andReturn($order);

    $errors         = [];
    $messageManager = Mockery::mock(ManagerInterface::class);
    $messageManager->shouldReceive('addErrorMessage')
        ->andReturnUsing(function (string $message) use (&$errors, $messageManager) {
            $errors[] = $message;

            return $messageManager;
        });

    $controller = new FakeCreateAndPrintMyParcelTrack($objectManager, $messageManager);
    $kept       = (new ReflectionMethod(CreateAndPrintMyParcelTrack::class, 'filterCorrectAddress'))
        ->invoke($controller, ['1']);

    return ['kept' => $kept, 'errors' => $errors];
}

it('keeps an order with a valid dutch address', function () {
    expect(filterOrderAddress('2132 JE', 'NL'))->toBe(['kept' => ['1'], 'errors' => []]);
});

it('skips an order with a null postcode for belgium instead of crashing', function () {
    $result = filterOrderAddress(null, 'BE', ['Rue de la Loi 16']);

    expect($result['kept'])->toBe([])
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('Check postcode');
});

it('keeps an order with a null postcode for a country without postcode validation', function () {
    expect(filterOrderAddress(null, 'DE', ['Hauptstraße 1']))->toBe(['kept' => ['1'], 'errors' => []]);
});
