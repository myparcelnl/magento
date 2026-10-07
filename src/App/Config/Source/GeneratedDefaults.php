<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\App\Config\Source;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ConfigSourceInterface;
use Magento\Framework\App\Config\Scope\Converter;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DataObject;
use MyParcelNL\Magento\Model\Settings\Blueprint\Generator;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The module's config defaults, generated from the field templates for every account on the install.
 *
 * Takes the place of etc/config.xml: it sits at sortOrder 5, below every other source, so a saved
 * row always wins. Default scope only, because a store-scope default would beat a merchant's
 * website row in the scope fallback.
 *
 * It runs while the config itself is being built, so nothing in its graph may read config. Its
 * dependencies are RuntimeConfigSource's, plus Fingerprint. ScopeConfigInterface or Service\Config
 * here recurses into this source.
 */
class GeneratedDefaults implements ConfigSourceInterface
{
    private DeploymentConfig $deploymentConfig;

    private CollectionFactory $collectionFactory;

    private Converter $converter;

    private Fingerprint $fingerprint;

    private LoggerInterface $logger;

    public function __construct(
        DeploymentConfig  $deploymentConfig,
        CollectionFactory $collectionFactory,
        Converter         $converter,
        Fingerprint       $fingerprint,
        LoggerInterface   $logger
    ) {
        $this->deploymentConfig  = $deploymentConfig;
        $this->collectionFactory = $collectionFactory;
        $this->converter         = $converter;
        $this->fingerprint       = $fingerprint;
        $this->logger            = $logger;
    }

    public function get($path = '')
    {
        $tree = new DataObject(['default' => $this->converter->convert($this->defaults())]);

        return $tree->getData($path) ?? null;
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        // The general section needs no account, so it survives a database that cannot be read.
        $defaults = Generator::for(CapabilitySet::permissive())->defaults();

        if (! $this->deploymentConfig->isDbAvailable()) {
            return $defaults;
        }

        try {
            $items = $this->contractDefinitionItems();
        } catch (Throwable $e) {
            $this->logger->error('MyParcel: carrier config defaults could not be generated: ' . $e->getMessage());

            return $defaults;
        }

        if ([] === $items) {
            return $defaults;
        }

        // The default proposition has every insurance zone, so a Dutch account's Belgian cap has its default.
        return array_merge(
            $defaults,
            Generator::for(CapabilitySet::fromContractDefinitionItems($items), [], Proposition::default())->defaults()
        );
    }

    /**
     * Every account's contract definitions, at every scope that holds an api key. Their union gives
     * every carrier any store can ship with, and a carrier's defaults are the same for every account.
     */
    private function contractDefinitionItems(): array
    {
        $paths = [];

        foreach ($this->rows(['path' => Config::XML_PATH_API_KEY]) as $row) {
            $apiKey = (string) $row->getData('value');

            if ('' !== $apiKey) {
                $paths[] = Config::XML_PATH_ACCOUNT_SETTINGS . $this->fingerprint->of($apiKey);
            }
        }

        if ([] === $paths) {
            return [];
        }

        $items = [];

        foreach ($this->rows(['path' => ['in' => array_values(array_unique($paths))], 'scope' => 'default']) as $row) {
            $decoded = json_decode((string) $row->getData('value'), true);

            if (is_array($decoded['contract_definitions'] ?? null)) {
                array_push($items, ...array_values($decoded['contract_definitions']));
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed> $filters
     * @return DataObject[]
     */
    private function rows(array $filters): array
    {
        $collection = $this->collectionFactory->create();

        foreach ($filters as $field => $condition) {
            $collection->addFieldToFilter($field, $condition);
        }

        return $collection->getItems();
    }
}
