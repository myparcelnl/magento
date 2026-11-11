<?php

declare(strict_types=1);

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use MyParcelNL\Magento\Model\Checkout\LayoutProcessorPlugin;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Service\Config;

/** A checkout layout down to the shipping address, with $form as its before-shipping-method-form. */
function checkoutLayoutWithForm(?array $form): array
{
    $address = ['children' => []];

    if (null !== $form) {
        $address['children']['before-shipping-method-form'] = $form;
    }

    return ['components' => ['checkout' => ['children' => ['steps' => ['children' => [
        'shipping-step' => ['children' => ['shippingAddress' => $address]],
    ]]]]]];
}

function processCheckoutLayout(array $jsLayout): array
{
    return (new LayoutProcessorPlugin(Mockery::mock(Checkout::class)))
        ->afterProcess(Mockery::mock(LayoutProcessor::class), $jsLayout);
}

function beforeShippingMethodForm(array $jsLayout): ?array
{
    return $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
        ['children']['shippingAddress']['children']['before-shipping-method-form'] ?? null;
}

it('adds the delivery options field beside what the form already holds', function () {
    $form = beforeShippingMethodForm(processCheckoutLayout(checkoutLayoutWithForm(['children' => ['other' => []]])));

    expect(array_keys($form['children']))->toBe(['other', Config::FIELD_DELIVERY_OPTIONS]);
});

it('adds the field to a form that has no children yet', function () {
    $form = beforeShippingMethodForm(processCheckoutLayout(checkoutLayoutWithForm([])));

    expect($form['children'])->toHaveKey(Config::FIELD_DELIVERY_OPTIONS);
});

it('leaves a layout without the form as it is, as when another module removed it', function () {
    $layout = checkoutLayoutWithForm(null);

    expect(processCheckoutLayout($layout))->toBe($layout);
});
