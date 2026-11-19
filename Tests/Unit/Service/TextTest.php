<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Text;

it('keeps text within the limit as it is', function () {
    expect(Text::limit('Widget', 10))->toBe('Widget');
});

it('cuts to the limit in characters, the ellipsis included', function () {
    expect(Text::limit(str_repeat('a', 12), 10))->toBe('aaaaaaa...');
});

it('counts a combining accent as a character, as the SDK setters do', function () {
    expect(mb_strlen(Text::limit(str_repeat("e\u{0301}", 10), 10)))->toBe(10);
});
