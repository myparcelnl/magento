<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;
use Psr\Log\LoggerInterface;

/**
 * Collapses print/weight_indication to a single default-scope row.
 *
 * A product's weight is one number for every store — Magento's `weight` attribute is global — so a
 * unit that reads "grams" in one scope and "kilos" in another makes that one number mean two things
 * at once. Only one reading can be right, and the setting therefore has no scope.
 *
 * Deleting the scoped rows outright would silently change the unit for a merchant who only ever
 * configured it below default, and a wrong unit is a factor of 1000 on every mailbox decision. So a
 * value the scoped rows agree on is promoted to default first; rows that disagree are logged before
 * they go, because no automatic choice between them is defensible.
 *
 * Idempotent: once no non-default row is left, a second run does nothing.
 */
class UnscopeWeightIndication
{
    public const PATH = Config::XML_PATH_GENERAL . 'print/weight_indication';

    private CollectionFactory $collectionFactory;
    private WriterInterface   $configWriter;
    private LoggerInterface   $logger;

    public function __construct(
        CollectionFactory $collectionFactory,
        WriterInterface   $configWriter,
        LoggerInterface   $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
        $this->logger            = $logger;
    }

    public function run(): void
    {
        $default = null;
        $scoped  = [];

        foreach ($this->rows() as $row) {
            if ($this->isDefaultScope($row)) {
                $default = (string) $row->getData('value');
                continue;
            }

            $scoped[] = $row;
        }

        if (! $scoped) {
            return;
        }

        $this->promoteOrReport($scoped, $default);

        foreach ($scoped as $row) {
            $this->configWriter->delete(self::PATH, (string) $row->getData('scope'), (int) $row->getData('scope_id'));
        }
    }

    /**
     * @param \Magento\Framework\DataObject[] $scoped
     */
    private function promoteOrReport(array $scoped, ?string $default): void
    {
        $values = array_values(array_unique(array_map(
            static fn($row): string => (string) $row->getData('value'),
            $scoped
        )));

        if (1 === count($values)) {
            if ($values[0] !== $default) {
                $this->configWriter->save(self::PATH, $values[0]);
                $this->logger->notice(sprintf(
                    'MyParcel weight type is no longer a scoped setting. Every scope agreed on "%s", so that is now the one setting.',
                    $values[0]
                ));
            }

            return;
        }

        foreach ($scoped as $row) {
            $this->logger->notice(sprintf(
                'MyParcel weight type is no longer a scoped setting. Removed "%s" from %s %s; the default "%s" now applies everywhere.',
                (string) $row->getData('value'),
                (string) $row->getData('scope'),
                (string) $row->getData('scope_id'),
                (string) $default
            ));
        }
    }

    private function isDefaultScope($row): bool
    {
        return ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $row->getData('scope')
            && 0 === (int) $row->getData('scope_id');
    }

    /**
     * @return \Magento\Framework\DataObject[]
     */
    private function rows(): array
    {
        return array_values($this->collectionFactory->create()
            ->addFieldToFilter('path', self::PATH)
            ->getItems());
    }
}
