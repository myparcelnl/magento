<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Framework\App\ResourceConnection;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Service\Config;
use Psr\Log\LoggerInterface;

/**
 * Rewrites a stored false shipment option to null on orders and active quotes.
 *
 * The checkout wrote false for an option it offered but the customer did not tick. A stored false
 * now means a merchant switched the option off, so old rows must inherit instead. Idempotent.
 */
class ShipmentOptionsFalseToNull
{
    private const BATCH = 500;

    private ResourceConnection $resourceConnection;
    private LoggerInterface    $logger;

    public function __construct(ResourceConnection $resourceConnection, LoggerInterface $logger)
    {
        $this->resourceConnection = $resourceConnection;
        $this->logger             = $logger;
    }

    public function run(): void
    {
        $this->migrate('sales_order', '');
        $this->migrate('quote', ' AND is_active = 1');
    }

    private function migrate(string $tableName, string $extraWhere): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName($tableName);
        $column     = Config::FIELD_DELIVERY_OPTIONS;
        $last       = 0;

        do {
            $rows = $connection->fetchAll(
                "SELECT entity_id, {$column} FROM {$table}"
                . " WHERE entity_id > :last AND {$column} LIKE '%false%'{$extraWhere}"
                . ' ORDER BY entity_id LIMIT ' . self::BATCH,
                ['last' => $last]
            );

            // One commit per batch, not per row: a large shop has a row for nearly every order.
            $connection->beginTransaction();

            try {
                foreach ($rows as $row) {
                    $last = (int) $row['entity_id'];
                    $data = json_decode((string) $row[$column], true);

                    if (! is_array($data)) {
                        $this->logger->warning(sprintf('MyParcel: %s %d has unreadable delivery options; left as it was.', $tableName, $last));

                        continue;
                    }

                    $migrated = DeliveryOptions::inheritUnticked($data);

                    if ($migrated === $data) {
                        continue;
                    }

                    $connection->update(
                        $table,
                        [$column => json_encode($migrated, JSON_THROW_ON_ERROR)],
                        ['entity_id = ?' => $last]
                    );
                }

                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();

                throw $e;
            }
        } while (self::BATCH === count($rows));
    }
}
