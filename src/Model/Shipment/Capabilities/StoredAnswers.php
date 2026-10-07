<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\FlagManager;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

/**
 * The last good capabilities answer per shape, kept in Magento's flag table.
 *
 * Repository serves it when the cache has no answer and a fetch cannot run, so a cache:clean or a
 * Redis restart serves an old answer rather than everything. Not the config table: that one is
 * loaded whole into the config cache on every request.
 */
class StoredAnswers
{
    private const CODE_PREFIX = 'myparcel_capabilities_';
    private const FLAG_TABLE  = 'flag';

    private FlagManager        $flagManager;
    private ResourceConnection $resourceConnection;
    private Fingerprint        $fingerprint;

    public function __construct(
        FlagManager        $flagManager,
        ResourceConnection $resourceConnection,
        Fingerprint        $fingerprint
    ) {
        $this->flagManager        = $flagManager;
        $this->resourceConnection = $resourceConnection;
        $this->fingerprint        = $fingerprint;
    }

    /** @param array $results the response's `results` entries, as the cache holds them */
    public function save(string $apiKey, string $shape, array $results): void
    {
        $this->flagManager->saveFlag($this->code($apiKey, $shape), $results);
    }

    public function load(string $apiKey, string $shape): ?array
    {
        $results = $this->flagManager->getFlagData($this->code($apiKey, $shape));

        return is_array($results) ? $results : null;
    }

    /** @param string[] $liveKeyFingerprints the fingerprints of every api key still configured */
    public function deleteExcept(array $liveKeyFingerprints): void
    {
        $live = array_flip($liveKeyFingerprints);
        $dead = [];

        foreach ($this->storedCodes() as $id => $code) {
            $keyFingerprint = substr($code, strlen(self::CODE_PREFIX), 64);

            if (! isset($live[$keyFingerprint])) {
                $dead[] = $id;
            }
        }

        if ([] !== $dead) {
            $this->connection()->delete($this->table(), ['flag_id IN (?)' => $dead]);
        }
    }

    public function deleteAll(): void
    {
        $this->deleteExcept([]);
    }

    /** The flag code holds the key's fingerprint, never the key, so reconcile can match it. */
    private function code(string $apiKey, string $shape): string
    {
        return self::CODE_PREFIX . $this->fingerprint->of($apiKey) . '_' . $shape;
    }

    /** @return array<int, string> flag id => code, for this class's flags only */
    private function storedCodes(): array
    {
        $connection = $this->connection();
        $codes      = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(), ['flag_id', 'flag_code'])
                ->where('flag_code LIKE ?', self::CODE_PREFIX . '%')
        );

        // LIKE reads "_" as any character, so check the prefix itself.
        return array_filter($codes, static function (string $code): bool {
            return 0 === strpos($code, self::CODE_PREFIX);
        });
    }

    private function connection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::FLAG_TABLE);
    }
}
