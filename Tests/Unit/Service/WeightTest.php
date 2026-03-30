<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

function createConfig(array $values = []): Config
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')
        ->andReturnUsing(function (string $code) use ($values) {
            return $values[$code] ?? null;
        });

    return $config;
}

function createQuoteItem(float $weight, float $qty): QuoteItem
{
    $item = Mockery::mock(QuoteItem::class);
    $item->shouldReceive('getWeight')->andReturn($weight);
    $item->shouldReceive('getQty')->andReturn($qty);

    return $item;
}

// convertToGrams

it('converts kilograms to grams', function () {
    $config = createConfig(['print/weight_indication' => 'kilo']);
    $weight = new Weight($config);

    expect($weight->convertToGrams(2.5))->toBe(2500);
});

it('returns weight as-is when unit is grams', function () {
    $config = createConfig(['print/weight_indication' => 'gram']);
    $weight = new Weight($config);

    expect($weight->convertToGrams(500.0))->toBe(500);
});

it('returns DEFAULT_WEIGHT when input is null', function () {
    $config = createConfig(['print/weight_indication' => 'gram']);
    $weight = new Weight($config);

    expect($weight->convertToGrams(null))->toBe(Weight::DEFAULT_WEIGHT);
});

it('returns DEFAULT_WEIGHT when input is zero', function () {
    $config = createConfig(['print/weight_indication' => 'gram']);
    $weight = new Weight($config);

    expect($weight->convertToGrams(0.0))->toBe(Weight::DEFAULT_WEIGHT);
});

// getEmptyPackageWeightInGrams

it('returns configured weight for package type', function () {
    $config = createConfig(['empty_package_weight/package' => '200']);
    $weight = new Weight($config);

    expect($weight->getEmptyPackageWeightInGrams(AbstractConsignment::PACKAGE_TYPE_PACKAGE))->toBe(200);
});

it('returns configured weight for mailbox type', function () {
    $config = createConfig(['empty_package_weight/mailbox' => '50']);
    $weight = new Weight($config);

    expect($weight->getEmptyPackageWeightInGrams(AbstractConsignment::PACKAGE_TYPE_MAILBOX))->toBe(50);
});

it('returns 0 for invalid package type', function () {
    $config = createConfig();
    $weight = new Weight($config);

    expect($weight->getEmptyPackageWeightInGrams(999))->toBe(0);
});

// getQuoteWeightInGrams

it('calculates total weight from multiple products in grams', function () {
    $config = createConfig(['print/weight_indication' => 'gram']);
    $weight = new Weight($config);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([
        createQuoteItem(300.0, 2),
        createQuoteItem(150.0, 1),
    ]);

    expect($weight->getQuoteWeightInGrams($quote))->toBe(750);
});

it('calculates total weight from products in kilo mode', function () {
    $config = createConfig(['print/weight_indication' => 'kilo']);
    $weight = new Weight($config);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([
        createQuoteItem(1.5, 2),
    ]);

    expect($weight->getQuoteWeightInGrams($quote))->toBe(3000);
});

it('skips products with zero weight', function () {
    $config = createConfig(['print/weight_indication' => 'gram']);
    $weight = new Weight($config);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([
        createQuoteItem(0.0, 5),
        createQuoteItem(200.0, 1),
    ]);

    expect($weight->getQuoteWeightInGrams($quote))->toBe(200);
});

it('skips products with qty less than 1', function () {
    $config = createConfig(['print/weight_indication' => 'gram']);
    $weight = new Weight($config);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([
        createQuoteItem(500.0, 0.5),
        createQuoteItem(200.0, 2),
    ]);

    expect($weight->getQuoteWeightInGrams($quote))->toBe(400);
});

it('returns DEFAULT_WEIGHT for empty quote', function () {
    $config = createConfig(['print/weight_indication' => 'gram']);
    $weight = new Weight($config);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([]);

    expect($weight->getQuoteWeightInGrams($quote))->toBe(Weight::DEFAULT_WEIGHT);
});
