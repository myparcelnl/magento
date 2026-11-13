<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
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
use Psr\Log\LoggerInterface;

afterEach(function () {
    (new ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, null);
});

/**
 * Runs OrderAmount on items as Magento's tax service returns them. Each row: code, type, parent code, discount,
 * and the row total, row tax and discount tax compensation that Magento calculated.
 *
 * @param array<int, array{0: string, 1: string, 2: ?string, 3: float, 4: float, 5: float, 6: float}> $rows
 * @param bool     $asList      the tax service returns its items as a plain list, not keyed by code
 * @param string[] $notReturned codes the tax service leaves out of its result
 */
function orderAmountFor(array $rows, bool $priceIncludesTax = false, bool $asList = false, array $notReturned = []): float
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
        $taxed->shouldReceive('getCode')->andReturn($code);
        $taxed->shouldReceive('getRowTotal')->andReturn($rowTotal);
        $taxed->shouldReceive('getRowTax')->andReturn($rowTax);
        $taxed->shouldReceive('getDiscountTaxCompensationAmount')->andReturn($compensation);
        $taxedItems[$code] = $taxed;
    }

    // inside a collect: the tax subtotal collector gave the item a tax calculation price
    $quoteItem = Mockery::mock(Item::class);
    $quoteItem->shouldReceive('getParentItem')->andReturn(null);
    $quoteItem->shouldReceive('getTaxCalculationPrice')->andReturn(66.07);
    $quoteItems = $rows ? [$quoteItem] : [];
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
    $returned = array_diff_key($taxedItems, array_flip($notReturned));
    $taxDetails->shouldReceive('getItems')->andReturn($asList ? array_values($returned) : $returned);
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

it('adds row total and tax and subtracts the discount, so the review case comes to exactly 79.00', function () {
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

it('adds the discount tax compensation', function () {
    // 100.00 incl. 21% tax with 10.00 off: 82.64 - 10.00 + 15.62 tax + 1.74 compensation
    expect(orderAmountFor([['a', 'product', null, 10.0, 82.64, 15.62, 1.74]], true))->toBe(90.0);
});

it('counts the items with a parent code, not their parent', function () {
    expect(orderAmountFor([
        ['bundle', 'product', null, 0.0, 30.0, 6.3, 0.0],
        ['child-1', 'product', 'bundle', 0.0, 10.0, 2.1, 0.0],
        ['child-2', 'product', 'bundle', 0.0, 20.0, 4.2, 0.0],
    ]))->toBe(36.3);
});

it('leaves out items whose type is not product', function () {
    expect(orderAmountFor([
        ['a', 'product', null, 0.0, 10.0, 2.1, 0.0],
        ['a-weee', 'weee', null, 0.0, 5.0, 1.05, 0.0],
    ]))->toBe(12.1);
});

it('takes 0 for a quote without items, without asking the tax service', function () {
    expect(orderAmountFor([]))->toBe(0.0);
});

/**
 * A quote item with the totals Magento saved at its last collect. Without a tax calculation price it was not
 * mapped in this request, as on a quote that the session loaded without collecting it.
 */
function savedQuoteItem(
    float $rowTotal,
    float $discount,
    float $tax,
    float $compensation = 0.0,
    ?Item $parent = null,
    bool $childrenCalculated = false,
    ?float $taxCalculationPrice = null
): Item {
    $item = Mockery::mock(Item::class);
    $item->shouldReceive('getParentItem')->andReturn($parent);
    $item->shouldReceive('getHasChildren')->andReturn($childrenCalculated);
    $item->shouldReceive('isChildrenCalculated')->andReturn($childrenCalculated);
    $item->shouldReceive('getTaxCalculationPrice')->andReturn($taxCalculationPrice);
    $item->shouldReceive('getRowTotal')->andReturn($rowTotal);
    $item->shouldReceive('getDiscountAmount')->andReturn($discount);
    $item->shouldReceive('getTaxAmount')->andReturn($tax);
    $item->shouldReceive('getDiscountTaxCompensationAmount')->andReturn($compensation);

    return $item;
}

/** Runs OrderAmount on a quote that was not collected: it must not map the items nor ask the tax service. */
function savedOrderAmountFor(array $quoteItems): float
{
    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn($quoteItems);

    // mapItems() would write the saved net price back to the item as its tax calculation price
    $collector = Mockery::mock(CommonTaxCollector::class);
    $collector->shouldNotReceive('mapItems');
    $taxCalculation = Mockery::mock(TaxCalculationInterface::class);
    $taxCalculation->shouldNotReceive('calculateTax');

    return (new OrderAmount(
        $taxCalculation,
        $collector,
        Mockery::mock(QuoteDetailsInterfaceFactory::class),
        Mockery::mock(TaxClassKeyInterfaceFactory::class),
        Mockery::mock(ShippingAssignmentInterfaceFactory::class),
        Mockery::mock(TaxConfig::class)
    ))->get($quote);
}

it('takes the saved totals of a quote that is not collected, without mapping its items', function () {
    // Freek's case: 79.00 incl. tax, saved by the last collect as 65.29 net and 13.71 tax
    expect(savedOrderAmountFor([savedQuoteItem(65.29, 0.0, 13.71)]))->toBe(79.0)
        ->and(savedOrderAmountFor([savedQuoteItem(19.99, 0.0, 4.2), savedQuoteItem(19.99, 0.0, 4.2)]))->toBe(48.38);
});

it('subtracts the saved discount and adds its tax compensation', function () {
    expect(savedOrderAmountFor([
        savedQuoteItem(66.07, 0.78, 13.71),
        savedQuoteItem(82.64, 10.0, 15.62, 1.74),
    ]))->toBe(169.0);
});

it('counts the children of a dynamic bundle from the saved totals, not the parent that sums them', function () {
    $bundle = savedQuoteItem(30.0, 0.0, 6.3, 0.0, null, true);

    expect(savedOrderAmountFor([
        $bundle,
        savedQuoteItem(10.0, 0.0, 2.1, 0.0, $bundle),
        savedQuoteItem(20.0, 0.0, 4.2, 0.0, $bundle),
    ]))->toBe(36.3);
});

it('counts a configurable through its parent, not through its child', function () {
    $configurable = savedQuoteItem(50.0, 0.0, 10.5);

    expect(savedOrderAmountFor([$configurable, savedQuoteItem(50.0, 0.0, 10.5, 0.0, $configurable)]))->toBe(60.5);
});

it('treats a quote as mapped when only the child of a configurable has no tax calculation price', function () {
    // mapItems() skips that child, so inside a collect it never gets one
    $configurable = savedQuoteItem(50.0, 0.0, 10.5, 0.0, null, false, 50.0);
    $child        = savedQuoteItem(0.0, 0.0, 0.0, 0.0, $configurable);
    $isMapped     = static fn (array $items): bool => invokePrivateMethod(newInstanceWithoutConstructor(OrderAmount::class), 'isMappedForTax', [$items]);

    expect($isMapped([$configurable, $child]))->toBeTrue()
        ->and($isMapped([savedQuoteItem(65.29, 0.0, 13.71)]))->toBeFalse();
});

it('keys the result of the tax service by code, as the interface promises no keys', function () {
    expect(orderAmountFor([
        ['a', 'product', null, 0.0, 19.99, 4.2, 0.0],
        ['b', 'product', null, 0.0, 19.99, 4.2, 0.0],
    ], false, true))->toBe(48.38);
});

it('leaves out and logs a product the tax service did not return, instead of breaking the checkout', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('notice')->once()->with(Mockery::type('string'), ['codes' => ['b']]);
    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(LoggerInterface::class)->andReturn($logger);
    ObjectManager::setInstance($objectManager);

    expect(orderAmountFor([
        ['a', 'product', null, 0.0, 10.0, 2.1, 0.0],
        ['b', 'product', null, 0.0, 5.0, 1.05, 0.0],
    ], false, false, ['b']))->toBe(12.1);
});
