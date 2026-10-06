<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use Throwable;

/**
 * The proposition behind a store's api key, read from stored account settings — no API call.
 *
 * Null when the store has no api key, its settings were never imported or cannot be read, or its
 * account names a proposition Proposition does not list; that last one logs a warning. Never throws:
 * the order grid asks per row, so an unusable row must not cost the page.
 *
 * Memoised per request: a grid page may hold orders from many stores, and the same store's settings
 * must not be deserialised once per row. A null is memoised too, or one broken row logs once per row.
 */
class AccountProposition
{
    private ShipmentApiProvider $apiProvider;

    /** @var array<int, Proposition|null> */
    private array $byStore = [];

    public function __construct(ShipmentApiProvider $apiProvider)
    {
        $this->apiProvider = $apiProvider;
    }

    public function forStore(?int $storeId): ?Proposition
    {
        if (null === $storeId) {
            return null;
        }

        if (! array_key_exists($storeId, $this->byStore)) {
            $this->byStore[$storeId] = $this->read($storeId);
        }

        return $this->byStore[$storeId];
    }

    private function read(int $storeId): ?Proposition
    {
        try {
            $apiKey = $this->apiProvider->apiKeyForStoreOrNull($storeId);

            if (null === $apiKey) {
                return null;
            }

            $account = (new AccountSettings($apiKey))->getAccount();
        } catch (Throwable $e) {
            Logger::alert(
                sprintf('Could not establish the account proposition for store %d.', $storeId),
                LogContext::of($e)
            );

            return null;
        }

        if (null === $account) {
            return null;
        }

        $proposition = Proposition::forId($account->getPropositionId());

        if (null === $proposition) {
            Logger::warning(sprintf(
                'The account of store %d has proposition %d, which this module does not list.',
                $storeId,
                $account->getPropositionId()
            ));
        }

        return $proposition;
    }
}
