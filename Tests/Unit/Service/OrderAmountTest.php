<?php

declare(strict_types=1);

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\Store;
use Magento\Tax\Api\Data\QuoteDetailsInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsInterface;
use Magento\Tax\Api\Data\TaxDetailsItemInterface;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;
use MyParcelNL\Magento\Service\OrderAmount;

/**
 * Runs OrderAmount on items as Magento's tax service returns them. Each row: code, type, parent code, discount,
 * and the row total, row tax and discount tax compensation that Magento calculated.
 *
 * @param array<int, array{0: string, 1: string, 2: ?string, 3: float, 4: float, 5: float, 6: float}> $rows
 */
function orderAmountFor(array $rows, bool $priceIncludesTax = false): float
{
    $asked      = $rows ? 1 : 0;
    $items      = [];
    $taxedItems = [];
    foreach ($rows as [$code, $type, $parentCode, $discount, $rowTotal, $rowTax, $compensation]) {
        $item = Mockery::mock(QuoteDetailsItemInterface::class);
        $item->shouldReceive('getCode')->andReturn($code);
        $item->shouldReceive('getType')->andReturn($type);
        $item->shouldReceive('getParentCode')->andReturn($parentCode);
        $item->shouldReceive('getDiscountAmount')->andReturn($discount);
        $items[] = $item;

        $taxed = Mockery::mock(TaxDetailsItemInterface::class);
        $taxed->shouldReceive('getRowTotal')->andReturn($rowTotal);
        $taxed->shouldReceive('getRowTax')->andReturn($rowTax);
        $taxed->shouldReceive('getDiscountTaxCompensationAmount')->andReturn($compensation);
        $taxedItems[$code] = $taxed;
    }

    $quoteItems = $rows ? [Mockery::mock(Item::class)] : [];
    $address    = Mockery::mock(Address::class);
    $store      = Mockery::mock(Store::class);
    $store->shouldReceive('getStoreId')->andReturn('3');

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn($quoteItems);
    $quote->shouldReceive('getStore')->andReturn($store);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);
    $quote->shouldReceive('getCustomerTaxClassId')->andReturn(5);
    $quote->shouldReceive('getCustomerId')->andReturn(7);

    $assignment = Mockery::mock(ShippingAssignmentInterface::class);
    $assignment->shouldReceive('setItems')->with($quoteItems)->andReturnSelf();
    $assignmentFactory = Mockery::mock(ShippingAssignmentInterfaceFactory::class);
    $assignmentFactory->shouldReceive('create')->andReturn($assignment);

    $taxConfig = Mockery::mock(TaxConfig::class);
    $taxConfig->shouldReceive('priceIncludesTax')->with($store)->andReturn($priceIncludesTax);

    $quoteDetails = Mockery::mock(QuoteDetailsInterface::class);
    $quoteDetails->shouldReceive('setItems')->with($items)->times($asked);
    $quoteDetails->shouldReceive('setCustomerId')->with(7)->times($asked);
    $quoteDetailsFactory = Mockery::mock(QuoteDetailsInterfaceFactory::class);
    $quoteDetailsFactory->shouldReceive('create')->andReturn($quoteDetails);

    $taxClassKey = Mockery::mock(TaxClassKeyInterface::class);
    $taxClassKey->shouldReceive('setType')->with(TaxClassKeyInterface::TYPE_ID)->times($asked)->andReturnSelf();
    $taxClassKey->shouldReceive('setValue')->with(5)->times($asked)->andReturnSelf();
    $taxClassKeyFactory = Mockery::mock(TaxClassKeyInterfaceFactory::class);
    $taxClassKeyFactory->shouldReceive('create')->andReturn($taxClassKey);
    $quoteDetails->shouldReceive('setCustomerTaxClassKey')->with($taxClassKey)->times($asked);

    // the items are mapped as the tax collector maps them, in the store view currency
    $collector = Mockery::mock(CommonTaxCollector::class);
    $collector->shouldReceive('mapItems')->with($assignment, $priceIncludesTax, false)->andReturn($items);
    $collector->shouldReceive('populateAddressData')->with($quoteDetails, $address)->times($asked)->andReturn($quoteDetails);

    $taxDetails = Mockery::mock(TaxDetailsInterface::class);
    $taxDetails->shouldReceive('getItems')->andReturn($taxedItems);
    $taxCalculation = Mockery::mock(TaxCalculationInterface::class);
    $taxCalculation->shouldReceive('calculateTax')->with($quoteDetails, 3)->times($asked)->andReturn($taxDetails);

    return (new OrderAmount(
        $taxCalculation,
        $collector,
        $quoteDetailsFactory,
        $taxClassKeyFactory,
        $assignmentFactory,
        $taxConfig
    ))->get($quote);
}

it('takes the tax Magento calculates after the discount, so an order on a threshold stays on it', function () {
    // the review case: 66.07 excl. tax, 0.78 discount, and Magento's 13.71 tax on the discounted 65.29
    expect(orderAmountFor([['a', 'product', null, 0.78, 66.07, 13.71, 0.0]]))->toBe(79.0);
});

it('adds up every product and rounds the sum to cents', function () {
    // two items of 19.99 with 4.20 tax add up to 48.379999999999995 as floats, which would miss a 48.38 threshold
    expect(orderAmountFor([
        ['a', 'product', null, 0.0, 19.99, 4.2, 0.0],
        ['b', 'product', null, 0.0, 19.99, 4.2, 0.0],
    ]))->toBe(48.38);
});

it('adds the discount tax compensation, for prices that include tax', function () {
    // 100.00 incl. 21% tax with 10.00 off: 82.64 - 10.00 + 15.62 tax + 1.74 compensation
    expect(orderAmountFor([['a', 'product', null, 10.0, 82.64, 15.62, 1.74]], true))->toBe(90.0);
});

it('counts the children of a bundle, not the parent that sums them', function () {
    expect(orderAmountFor([
        ['bundle', 'product', null, 0.0, 30.0, 6.3, 0.0],
        ['child-1', 'product', 'bundle', 0.0, 10.0, 2.1, 0.0],
        ['child-2', 'product', 'bundle', 0.0, 20.0, 4.2, 0.0],
    ]))->toBe(36.3);
});

it('leaves out item types that are no part of the subtotal, such as FPT', function () {
    expect(orderAmountFor([
        ['a', 'product', null, 0.0, 10.0, 2.1, 0.0],
        ['a-weee', 'weee', null, 0.0, 5.0, 1.05, 0.0],
    ]))->toBe(12.1);
});

it('never takes a negative order amount', function () {
    expect(orderAmountFor([['a', 'product', null, 15.0, 10.0, 0.0, 0.0]]))->toBe(0.0);
});

it('takes 0 for a quote without items, without asking the tax service', function () {
    expect(orderAmountFor([]))->toBe(0.0);
});
