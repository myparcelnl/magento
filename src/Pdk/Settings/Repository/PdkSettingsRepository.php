<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Settings\Repository;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Pdk\Settings\Repository\AbstractPdkSettingsRepository;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;

class PdkSettingsRepository extends AbstractPdkSettingsRepository
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
        parent::__construct();
    }

    /**
     * @param  string $namespace
     *
     * @return mixed
     */
    public function getGroup(string $namespace)
    {
        return $this->retrieve($namespace, function () use ($namespace) {
            return $this->scopeConfig->getValue($namespace);
        });
    }

    /**
     * @param  string $key
     * @param         $value
     *
     * @return void
     */
    public function store(string $key, $value): void
    {
        $this->scopeConfig->setValue($key, $value);

        $this->save($key, $value);
    }
}
