<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Settings\Validator;

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
     * @param  mixed  $value the posted value; it can be null or an array
     * @return Phrase|null null when the value may be saved, otherwise the reason, without a full stop:
     *                     ConfigChange names the field and says whether the value was saved
     */
    public function validate(string $path, $value, string $scopeName, int $scopeId): ?Phrase;
}
