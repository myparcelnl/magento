<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\TrackTrace;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use Throwable;

/**
 * The platform id behind a store's api key, read from stored account settings — no API call.
 *
 * Answers null when the store has no api key, its settings were never imported, or the stored row
 * cannot be read at all, which the fallback url reads as "use the default host". Never throws: the
 * order grid asks per row, so an unusable row must cost the Belgian host and never the page.
 *
 * Memoised per request: a grid page may hold orders from many stores, and the same store's settings
 * must not be deserialised once per row. A null is memoised too, or one broken row logs once per row.
 */
class AccountPlatform
{
    private ShipmentApiProvider $apiProvider;

    /** @var array<int, int|null> */
    private array $byStore = [];

    public function __construct(ShipmentApiProvider $apiProvider)
    {
        $this->apiProvider = $apiProvider;
    }

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
            Logger::alert(
                sprintf(
                    'Could not establish the account platform for store %d; the default track & trace host is used.',
                    $storeId
                ),
                LogContext::of($e)
            );

            return null;
        }
    }
}
