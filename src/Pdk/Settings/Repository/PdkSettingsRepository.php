<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Settings\Repository;

use MyParcelNL\Pdk\Settings\Repository\AbstractPdkSettingsRepository;

class PdkSettingsRepository extends AbstractPdkSettingsRepository
{
    /**
     * @param  string $namespace
     *
     * @return mixed
     */
    public function getGroup(string $namespace)
    {
        return $this->retrieve($namespace, function () use ($namespace) {
            // todo: get option from database

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
        // todo: update option in database


        $this->save($key, $value);
    }
}
