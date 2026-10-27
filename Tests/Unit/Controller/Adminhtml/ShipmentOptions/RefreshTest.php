<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions\Refresh;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryDates;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsForm;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsFormFactory;

/** Renders the option block as a marker, so the test needs no layout. */
final class RefreshWithoutLayout extends Refresh
{
    /** @var RequestInterface|null Declared here: the test stub of Action has no $_request. */
    protected $_request;

    public function getRequest()
    {
        return $this->_request;
    }

    protected function optionsHtml(ShipmentOptionsForm $form, string $carrier, string $packageType, array $states): string
    {
        return '<options ' . implode(',', array_keys($states)) . '>';
    }
}

/**
 * A Refresh controller over a form double and a DeliveryDates double, called with these params.
 *
 * @param array<string,mixed> $params
 */
function refreshAnswer(array $params, ?DeliveryDates $dates = null, int $orderCount = 1): array
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->andReturnUsing(static fn(string $name, $default = null) => $params[$name] ?? $default);

    $form = Mockery::mock(ShipmentOptionsForm::class);
    $form->shouldReceive('optionStates')->with('postnl', 'package', 'morning', ['signature'])->andReturn(['signature' => ['checked' => true, 'disabled' => false, 'greyed' => false]]);
    $form->shouldReceive('emptyAnswer')->andReturn(false);
    $form->shouldReceive('allowedDeliveryTypes')->with('postnl', 'package', ['signature'])->andReturn(['standard', 'morning']);
    $form->shouldReceive('storedDelivery')->andReturn(storedDeliveryOptions(['carrier' => 'postnl', 'deliveryType' => 'standard']));

    $formFactory = Mockery::mock(ShipmentOptionsFormFactory::class);
    $formFactory->shouldReceive('create')->andReturn($form);

    $controller = newInstanceWithoutConstructor(RefreshWithoutLayout::class);
    setPrivateProperty($controller, '_request', $request);
    setPrivateProperty($controller, 'formFactory', $formFactory);
    setPrivateProperty($controller, 'deliveryDates', $dates ?? Mockery::mock(DeliveryDates::class));

    return invokePrivateMethod($controller, 'answer', [array_fill(0, $orderCount, createOrder())]);
}

const REFRESH_PARAMS = ['carrier' => 'postnl', 'package_type' => 'package', 'delivery_type' => 'morning', 'options' => ['signature', 'not a name']];

it('answers the allowed delivery types and the option block of the selection', function () {
    $answer = refreshAnswer(REFRESH_PARAMS);

    expect($answer)->toMatchArray([
        'allowedDeliveryTypes' => ['standard', 'morning'],
        'optionsHtml'          => '<options signature>',
        'emptyAnswer'          => false,
    ])->not->toHaveKey('deliveries');
});

it('adds the delivery list when asked with dates', function () {
    $dates = Mockery::mock(DeliveryDates::class);
    $dates->shouldReceive('forOrder')->andReturn([datedDelivery('2026-10-29', 'morning', '08:00', '12:00')]);

    $answer = refreshAnswer(REFRESH_PARAMS + ['with_dates' => '1'], $dates);

    expect(deliveryValues($answer['deliveries']))->toBe(['standard|', 'morning|2026-10-29'])
        ->and($answer['deliveries']['warning'])->toBeNull();
});

it('still answers the delivery list, with a warning, when the dates cannot be read', function () {
    mockLoggerFacade()->shouldReceive('notice');
    $dates = Mockery::mock(DeliveryDates::class);
    $dates->shouldReceive('forOrder')->andThrow(new RuntimeException('the delivery options answered 500'));

    $answer = refreshAnswer(REFRESH_PARAMS + ['with_dates' => '1'], $dates);

    expect(deliveryValues($answer['deliveries']))->toBe(['standard|'])
        ->and($answer['deliveries']['warning'])->toBeString();
});

it('refuses more than one order', function () {
    refreshAnswer(REFRESH_PARAMS, null, 2);
})->throws(LocalizedException::class);
