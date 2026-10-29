<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions\Refresh;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryDates;
use MyParcelNL\Magento\Tests\Stub\WithTestRequest;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsForm;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsFormFactory;

/** Renders the option block as a marker, so the test needs no layout. */
final class RefreshWithoutLayout extends Refresh
{
    use WithTestRequest;

    protected function optionsHtml(ShipmentOptionsForm $form, string $carrier, string $packageType, array $states, bool $emptyAnswer): string
    {
        return '<options ' . implode(',', array_keys($states)) . '>';
    }
}

/**
 * A Refresh controller over a form double and a DeliveryDates double, called with these params.
 * The form answers option states only for the delivery type $statesFor, and allows standard and
 * morning with the ticked signature; without options it also offers pickup.
 *
 * @param array<string,mixed> $params
 */
function refreshAnswer(
    array          $params,
    ?DeliveryDates $dates = null,
    int            $orderCount = 1,
    array          $stored = ['carrier' => 'postnl', 'deliveryType' => 'standard'],
    string         $statesFor = 'morning',
    array          $allowedWithSignature = ['standard', 'morning']
): array {
    $form = Mockery::mock(ShipmentOptionsForm::class);
    $form->shouldReceive('optionStates')->with('postnl', 'package', $statesFor, ['signature'])->andReturn(['signature' => ['checked' => true, 'disabled' => false, 'greyed' => false]]);
    $form->shouldReceive('emptyAnswer')->with('postnl', 'package', $statesFor, ['signature'])->andReturn(false);
    $form->shouldReceive('allowedDeliveryTypes')->with('postnl', 'package', ['signature'])->andReturn($allowedWithSignature);
    $form->shouldReceive('allowedDeliveryTypes')->with('postnl', 'package', [])->andReturn(['standard', 'morning', 'pickup']);
    $form->shouldReceive('storedDelivery')->andReturn(storedDeliveryOptions($stored));

    $formFactory = Mockery::mock(ShipmentOptionsFormFactory::class);
    $formFactory->shouldReceive('create')->andReturn($form);

    return shipmentOptionsAnswer(
        RefreshWithoutLayout::class,
        $params,
        ['formFactory' => $formFactory, 'deliveryDates' => $dates ?? Mockery::mock(DeliveryDates::class)],
        $orderCount
    );
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

    $answer = refreshAnswer(REFRESH_PARAMS + ['with_dates' => '1'], $dates, 1, ['carrier' => 'postnl', 'deliveryType' => 'morning', 'date' => '2026-10-29 00:00:00']);

    expect(deliveryValues($answer['deliveries']))->toBe(['standard|', 'morning|2026-10-29', 'pickup|']);
});

it('builds the list from every type the carrier offers, and filters by the ticked options apart', function () {
    // Pickup is in the list although signature excludes it: the modal greys it out, and shows it
    // again when the merchant unticks signature, without a rebuild.
    $dates = Mockery::mock(DeliveryDates::class);
    $dates->shouldReceive('forOrder')->andReturn([]);

    $answer = refreshAnswer(REFRESH_PARAMS + ['with_dates' => '1'], $dates, 1, ['carrier' => 'postnl', 'deliveryType' => 'standard'], 'standard');

    expect($answer['deliveries']['pickup'])->toBeTrue()
        ->and($answer['allowedDeliveryTypes'])->toBe(['standard', 'morning']);
});

it('answers the option block for standard when the ticked options exclude the preselected delivery', function () {
    // The list preselects the stored morning; the modal falls back to standard because signature
    // excludes morning here, so the option block must follow standard too.
    $dates = Mockery::mock(DeliveryDates::class);
    $dates->shouldReceive('forOrder')->andReturn([datedDelivery('2026-10-29', 'morning', '08:00', '12:00')]);

    $answer = refreshAnswer(REFRESH_PARAMS + ['with_dates' => '1'], $dates, 1, ['carrier' => 'postnl', 'deliveryType' => 'morning', 'date' => '2026-10-29 00:00:00'], 'standard', ['standard']);

    expect(selectedDelivery($answer['deliveries']))->toBe('morning|2026-10-29')
        ->and($answer['optionsHtml'])->toBe('<options signature>');
});

it('answers the option block for the delivery the list preselects, not the posted one', function () {
    $dates = Mockery::mock(DeliveryDates::class);
    $dates->shouldReceive('forOrder')->andReturn([datedDelivery('2026-10-30', 'morning', '08:00', '12:00')]);

    // A stored morning without a date is not in the list, so the list preselects standard.
    $answer = refreshAnswer(REFRESH_PARAMS + ['with_dates' => '1'], $dates, 1, ['carrier' => 'postnl', 'deliveryType' => 'morning'], 'standard');

    expect(selectedDelivery($answer['deliveries']))->toBe('standard|')
        ->and($answer['optionsHtml'])->toBe('<options signature>');
});

it('still answers the delivery list, with a warning, when the dates cannot be read', function () {
    mockLoggerFacade()->shouldReceive('notice');
    $dates = Mockery::mock(DeliveryDates::class);
    $dates->shouldReceive('forOrder')->andThrow(new RuntimeException('the delivery options answered 500'));

    $answer = refreshAnswer(REFRESH_PARAMS + ['with_dates' => '1'], $dates, 1, ['carrier' => 'postnl', 'deliveryType' => 'standard'], 'standard');

    expect(deliveryValues($answer['deliveries']))->toBe(['standard|', 'pickup|'])
        ->and($answer['deliveries']['warning'])->toBeString();
});

it('refuses more than one order', function () {
    refreshAnswer(REFRESH_PARAMS, null, 2);
})->throws(LocalizedException::class);
