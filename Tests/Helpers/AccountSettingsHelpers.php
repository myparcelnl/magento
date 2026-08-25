<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

/**
 * The config path an api key's account settings belong at. Uses the real Fingerprint, so a test
 * expressing an expectation cannot drift from the algorithm production code uses.
 */
function settingsPathFor(string $apiKey): string
{
    return Config::XML_PATH_ACCOUNT_SETTINGS . (new Fingerprint())->of($apiKey);
}

/**
 * The pre-fingerprint path, where the plaintext api key sat in the path itself. Only the rewrite
 * patch may still encounter one.
 */
function legacySettingsPathFor(string $apiKey): string
{
    return Config::XML_PATH_ACCOUNT_SETTINGS . $apiKey;
}
