<?php

declare(strict_types=1);

namespace Mock;

use MyParcelNL\Magento\Tests\Mock\StaticMockInterface;

class MockMagentoCart implements StaticMockInterface
{
    public static function getQuote(): array
    {
        return [
            'items' => [
                [
                    'item_id' => 1,
                    'qty'     => 1,
                    'product' => [
                        'sku' => 'test',
                    ],
                ],
            ],
        ];
    }

    public static function getQuoteWithMultipleItems(): array
    {
        return [
            'items' => [
                [
                    'item_id' => 1,
                    'qty'     => 1,
                    'product' => [
                        'sku' => 'test',
                    ],
                ],
                [
                    'item_id' => 2,
                    'qty'     => 1,
                    'product' => [
                        'sku' => 'test2',
                    ],
                ],
            ],
        ];
    }

    public static function reset(): void
    {
        // TODO: Implement reset() method.
    }
}
