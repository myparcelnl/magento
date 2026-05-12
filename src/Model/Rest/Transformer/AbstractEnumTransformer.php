<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Support\Str;

abstract class AbstractEnumTransformer
{
    /**
     * @return string[]
     */
    abstract protected function getAllowableValues(): array;

    /**
     * Subclass-specific fallback when direct match and SCREAMING_SNAKE conversion both fail.
     *
     * @param string   $original   The original input value.
     * @param string   $converted  The SCREAMING_SNAKE_CASE-converted value.
     * @param string[] $allowed    The allowable enum values.
     */
    abstract protected function resolveFallback(string $original, string $converted, array $allowed): ?string;

    public function transform(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $allowed = $this->getAllowableValues();

        // 1. Direct match against the generated Order API enum.
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        // 2. SCREAMING_SNAKE_CASE conversion, then re-check.
        $converted = Str::upper(Str::snake($value));
        if (in_array($converted, $allowed, true)) {
            return $converted;
        }

        // 3. Subclass-specific fallback.
        return $this->resolveFallback($value, $converted, $allowed);
    }
}
