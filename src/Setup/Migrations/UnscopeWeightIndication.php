<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Service\Config;
use Psr\Log\LoggerInterface;

/**
 * Collapses print/weight_indication to a single default-scope row, because `weight` is global.
 *
 * A unit that every store view reads is promoted to default before the scoped rows go: a wrong unit
 * is a factor of 1000. When the store views disagree, the rows are logged before they go.
 * Idempotent: once no non-default row is left, a second run does nothing.
 */
class UnscopeWeightIndication
{
    public const PATH = Config::XML_PATH_GENERAL . 'print/weight_indication';

    /** The readers take anything but this as grams, an absent value included. */
    private const KILO = 'kilo';
    private const GRAM = 'gram';

    private CollectionFactory     $collectionFactory;
    private WriterInterface       $configWriter;
    private LoggerInterface       $logger;
    private StoreManagerInterface $storeManager;

    public function __construct(
        CollectionFactory     $collectionFactory,
        WriterInterface       $configWriter,
        LoggerInterface       $logger,
        StoreManagerInterface $storeManager
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
        $this->logger            = $logger;
        $this->storeManager      = $storeManager;
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
        $units = array_values(array_unique($this->unitPerStore($scoped, $default)));

        if (1 === count($units)) {
            if ($units[0] !== self::unitOf($default)) {
                $this->configWriter->save(self::PATH, $units[0]);
                $this->logger->notice(sprintf(
                    'MyParcel weight type is no longer a scoped setting. Every store view read "%s", so that is now the one setting.',
                    $units[0]
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

    /**
     * Get the unit each store view reads: its own row, else its website's, else the default.
     *
     * A row for a store or website that no longer exists takes no part.
     *
     * @param  \Magento\Framework\DataObject[] $scoped
     * @return array<int, string>
     */
    private function unitPerStore(array $scoped, ?string $default): array
    {
        $byScope = [];

        foreach ($scoped as $row) {
            $byScope[(string) $row->getData('scope')][(int) $row->getData('scope_id')] = (string) $row->getData('value');
        }

        $units = [];

        foreach ($this->storeManager->getStores() as $store) {
            $units[(int) $store->getId()] = self::unitOf(
                $byScope[ScopeInterface::SCOPE_STORES][(int) $store->getId()]
                ?? $byScope[ScopeInterface::SCOPE_WEBSITES][(int) $store->getWebsiteId()]
                ?? $default
            );
        }

        return $units;
    }

    private static function unitOf(?string $value): string
    {
        return self::KILO === $value ? self::KILO : self::GRAM;
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
