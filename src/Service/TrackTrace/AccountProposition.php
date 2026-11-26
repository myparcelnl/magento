<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\TrackTrace;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use Throwable;

/**
 * The proposition id behind a store's api key, read from stored account settings, with no API call.
 *
 * Answers null, and never throws, when the store has no api key, no imported settings or an
 * unreadable row; the fallback url then uses the default host. The order grid asks per row, so the
 * answer is memoised per store and request, a null included.
 */
class AccountProposition
{
    private ShipmentApiProvider $apiProvider;

    /** @var array<int, int|null> */
    private array $byStore = [];

    public function __construct(ShipmentApiProvider $apiProvider)
    {
        $this->apiProvider = $apiProvider;
    }

    /** Get the proposition id of this store's account, or null. Never throws, see the class docblock. */
    public function forStore(?int $storeId): ?int
    {
        if (null === $storeId) {
            return null;
        }

        if (array_key_exists($storeId, $this->byStore)) {
            return $this->byStore[$storeId];
        }

        return $this->byStore[$storeId] = $this->read($storeId);
    }

    private function read(int $storeId): ?int
    {
        try {
            $apiKey = $this->apiProvider->apiKeyForStoreOrNull($storeId);

            if (null === $apiKey) {
                return null;
            }

            $account = (new AccountSettings($apiKey))->getAccount();

            return $account ? $account->getPropositionId() : null;
        } catch (Throwable $e) {
            Logger::warning(
                sprintf(
                    'Could not establish the account proposition for store %d; the default track & trace host is used.',
                    $storeId
                ),
                LogContext::of($e)
            );

            return null;
        }
    }
}
