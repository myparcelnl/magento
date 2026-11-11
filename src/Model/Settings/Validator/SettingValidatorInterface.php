<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Validator;

use Magento\Framework\Phrase;

/**
 * Validates one kind of dynamic setting as it is saved.
 *
 * The admin settings form posts every field on every submit and Observer\ConfigChange writes them,
 * so a validator answers about one path at a time and a rejection costs that field only, never the
 * whole submission.
 */
interface SettingValidatorInterface
{
    /** Whether this validator has anything to say about the given config path. */
    public function handles(string $path): bool;

    /**
     * @param  mixed  $value the posted value, already flattened to a scalar
     * @return Phrase|null null when the value may be saved, otherwise the reason to show the admin
     */
    public function validate(string $path, $value, string $scopeName, int $scopeId): ?Phrase;
}
