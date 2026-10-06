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
 * Answers null, and never throws, when the store has no api key, no imported settings, an
 * unreadable row, or a proposition that Proposition does not list (logged as a warning). The order
 * grid asks per row, so the answer is memoised per store and request, a null included.
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

    /** Get the proposition of this store's account, or null. Never throws, see the class docblock. */
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

    /** The store's home country, or the default proposition's for a store without a listed one. */
    public function homeCountryForStore(?int $storeId): string
    {
        return ($this->forStore($storeId) ?? Proposition::default())->getCountryCode();
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
            Logger::warning(
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
