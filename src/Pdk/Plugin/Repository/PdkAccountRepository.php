<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Plugin\Repository;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Pdk\Account\Model\Account;
use MyParcelNL\Pdk\Account\Repository\AccountRepository;
use MyParcelNL\Pdk\App\Account\Repository\AbstractPdkAccountRepository;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Pdk\Settings\Contract\PdkSettingsRepositoryInterface;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;

class PdkAccountRepository extends AbstractPdkAccountRepository
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param  \MyParcelNL\Pdk\Storage\Contract\StorageInterface               $storage
     * @param  \MyParcelNL\Pdk\Account\Repository\AccountRepository            $accountRepository
     * @param  \MyParcelNL\Pdk\Settings\Contract\PdkSettingsRepositoryInterface $settingsRepository
     * @param  \Magento\Framework\App\Config\ScopeConfigInterface               $scopeConfig
     */
    public function __construct(
        StorageInterface               $storage,
        AccountRepository              $accountRepository,
        PdkSettingsRepositoryInterface $settingsRepository,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($storage, $accountRepository, $settingsRepository);
    }

    /**
     * @return \MyParcelNL\Pdk\Account\Model\Account|null
     */
    protected function getFromStorage(): ?Account
    {
        $account = $this->scopeConfig->getValue($this->getSettingKey());

        return $account ? new Account($account) : null;
    }

    /**
     * @param  \MyParcelNL\Pdk\Account\Model\Account|null $account
     *
     * @return \MyParcelNL\Pdk\Account\Model\Account|null
     * @throws \MyParcelNL\Pdk\Base\Exception\InvalidCastException
     */
    public function store(?Account $account): ?Account
    {
        $settingKey = $this->getSettingKey();

        $this->save('account', $account);

        if (! $account) {
            $this->scopeConfig->isSetFlag($settingKey);

            return $account;
        }

        $this->scopeConfig->setValue($settingKey, $account->toStorableArray());

        return $account;
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
