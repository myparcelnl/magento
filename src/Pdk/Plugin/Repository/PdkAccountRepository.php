<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Plugin\Repository;

use MyParcelNL\Pdk\Account\Model\Account;
use MyParcelNL\Pdk\App\Account\Repository\AbstractPdkAccountRepository;
use MyParcelNL\Pdk\Facade\Pdk;

class PdkAccountRepository extends AbstractPdkAccountRepository
{
    protected function getFromStorage(): ?Account
    {

    }

    public function store(?Account $account): ?Account
    {
        // TODO: Implement store() method.
    }

    /**
     * @return string
     */
    private function getSettingKey(): string
    {
        $appInfo = Pdk::getAppInfo();

        return sprintf('_%s_data_account', $appInfo->name);
    }
}
