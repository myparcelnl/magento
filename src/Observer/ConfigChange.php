<?php

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Facade\Logger;
use InvalidArgumentException;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Model\Settings\Validator\SettingValidatorInterface;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\AccountSettings\Maintenance as AccountSettingsMaintenance;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Service\Settings;
use Throwable;


class ConfigChange implements ObserverInterface
{
    private RequestInterface           $request;
    private WriterInterface            $configWriter;
    private TypeListInterface          $cacheTypeList;
    private Settings                   $dynamicSettingsConfig;
    private ManagerInterface           $messageManager;
    private ScopeConfigInterface       $scopeConfig;
    private ReinitableConfigInterface  $appConfig;
    private Importer                   $accountSettingsImporter;
    private AccountSettingsMaintenance $accountSettingsMaintenance;

    /** @var SettingValidatorInterface[] declared in etc/di.xml, one per kind of setting */
    private array $validators;

    public function __construct(
        RequestInterface           $request,
        WriterInterface            $configWriter,
        TypeListInterface          $cacheTypeList,
        Settings                   $dynamicSettingsConfig,
        ManagerInterface           $messageManager,
        ScopeConfigInterface       $scopeConfig,
        ReinitableConfigInterface  $appConfig,
        Importer                   $accountSettingsImporter,
        AccountSettingsMaintenance $accountSettingsMaintenance,
        array                      $validators = []
    )
    {
        foreach ($validators as $validator) {
            if (! $validator instanceof SettingValidatorInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Setting validators must implement %s, %s given.',
                    SettingValidatorInterface::class,
                    is_object($validator) ? get_class($validator) : gettype($validator)
                ));
            }
        }

        $this->request                    = $request;
        $this->configWriter               = $configWriter;
        $this->cacheTypeList              = $cacheTypeList;
        $this->dynamicSettingsConfig      = $dynamicSettingsConfig;
        $this->messageManager             = $messageManager;
        $this->scopeConfig                = $scopeConfig;
        $this->appConfig                  = $appConfig;
        $this->accountSettingsImporter    = $accountSettingsImporter;
        $this->accountSettingsMaintenance = $accountSettingsMaintenance;
        $this->validators                 = $validators;
    }

    /**
     * Saves every posted dynamic setting, then brings the stored account settings in step: import
     * for an api key that has none, and reconcile away rows for keys configured nowhere.
     *
     * The api key is saved and its account settings imported before any other field, because the
     * validators judge those fields against the contract of the key this save leaves in place.
     */
    public function execute(EventObserver $observer): self
    {
        $request    = $this->request;
        $scope      = $this->convertScope($request->getParam('scope', ScopeConfigInterface::SCOPE_TYPE_DEFAULT));
        $scopeId    = (int) $request->getParam('scope_id', 0);
        $posted     = (array) $request->getParam('config', []);
        $validPaths = array_values(array_filter(
            $this->dynamicSettingsConfig->getAllFieldPaths($scope, $scopeId),
            [Settings::class, 'isWritable']
        ));
        $configData = array_intersect_key($posted, array_flip($validPaths));

        $this->logSkipped(array_keys(array_diff_key($posted, $configData)), $scope, $scopeId);

        // Every field is posted on every submit, and each write costs a select, an update and a
        // message-queue poison-pill write of its own. Read the rows once and write only what moved.
        $stored = $this->dynamicSettingsConfig->storedValuesAtScope($validPaths, $scope, $scopeId);

        $apiKeyField = array_intersect_key($configData, [Config::XML_PATH_API_KEY => true]);

        try {
            if ([] !== $this->saveFields($apiKeyField, $stored, $scope, $scopeId)) {
                $this->reinitConfig(true);
            }

            $this->importMissingAccountSettings($scope, $scopeId);

            // A save that moved nothing has nothing to reload, and the reload is the expensive half.
            if ([] !== $this->saveFields(array_diff_key($configData, $apiKeyField), $stored, $scope, $scopeId)) {
                $this->reinitConfig(false);
            }

            $this->accountSettingsMaintenance->reconcile();
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error saving configuration: %1', $e->getMessage()));
        }

        return $this;
    }

    /**
     * Writes the posted fields that a validator accepts and that differ from their stored row.
     *
     * @param  array<string, array>       $fields posted params by path
     * @param  array<string, string|null> $stored the rows at this scope, by path
     * @return string[] the paths this call wrote or deleted
     */
    private function saveFields(array $fields, array $stored, string $scope, int $scopeId): array
    {
        $changed = [];

        foreach ($fields as $path => $postedParams) {
            $value   = $postedParams['value'] ?? null;
            $inherit = '1' === ($postedParams['inherit'] ?? '');
            $hasRow  = array_key_exists($path, $stored);

            // Handle checkbox "use default" - if inherit is set, delete the value for this scope
            if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $inherit) {
                if ($hasRow) {
                    $this->configWriter->delete($path, $scope, $scopeId);
                    $changed[] = $path;
                }
                continue;
            }

            if (is_array($value)) {
                $value = implode(',', $value);
            }

            // The one place the key is trimmed: every reader fingerprints the row by the key as stored.
            if (Config::XML_PATH_API_KEY === $path && is_string($value)) {
                $value = trim($value);
            }

            $rejection = $this->rejectionFor($path, $value, $scope, $scopeId);

            // Refuse this one field rather than the whole save: the form posts every field on
            // every submit, so failing the lot would make one bad value block every other change.
            if (null !== $rejection) {
                $this->messageManager->addErrorMessage($rejection);
                continue;
            }

            // Validated first, so a stored value that has since become invalid is still reported
            // even though this save leaves it alone.
            if ($hasRow && (string) $stored[$path] === (string) $value) {
                continue;
            }

            if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
                $this->configWriter->save($path, $value);
            } else {
                $this->configWriter->save($path, $value, $scope, $scopeId);
            }

            $changed[] = $path;
        }

        return $changed;
    }

    /**
     * A field that left the form between render and save loses its value, so say which.
     *
     * @param string[] $paths
     */
    private function logSkipped(array $paths, string $scope, int $scopeId): void
    {
        if ([] !== $paths) {
            Logger::notice(sprintf(
                'MyParcel settings: not saved, because the form at %s %d does not offer them: %s',
                $scope,
                $scopeId,
                implode(', ', $paths)
            ));
        }
    }

    /**
     * Whether the key changed is not worth detecting: an unchanged key already has its row, and a key
     * that does not is exactly the case worth importing — including a brand new one.
     */
    private function importMissingAccountSettings(string $scope, int $scopeId): void
    {
        // reinit() reset the in-memory config too, so this is the post-save key, not a cached one.
        $apiKey = (string) ($this->scopeConfig->getValue(Config::XML_PATH_API_KEY, $scope, $scopeId) ?? '');

        if ('' === $apiKey || $this->accountSettingsImporter->hasSettingsFor($apiKey)) {
            return;
        }

        // Before reconcile(), which deletes rows for unconfigured keys.
        $this->importAccountSettings($apiKey);
        // The import wrote a row of its own, so the merged config has to pick it up.
        $this->appConfig->reinit();
    }

    /**
     * The first reason any validator gives to refuse this field, or null when every one that claims
     * the path is satisfied. A path no validator claims is saved unexamined, which is every setting
     * in the form but the few that have a validator.
     *
     * @param mixed $value
     */
    private function rejectionFor(string $path, $value, string $scopeName, int $scopeId): ?Phrase
    {
        foreach ($this->validators as $validator) {
            if (! $validator->handles($path)) {
                continue;
            }

            $rejection = $validator->validate($path, $value, $scopeName, $scopeId);

            if (null !== $rejection) {
                return $rejection;
            }
        }

        return null;
    }

    /**
     * Failure is contained on purpose: an invalid key is no reason to refuse to store it, which would
     * leave the admin unable to save anything at all. Warn, let the save succeed, let them retry with
     * the button.
     */
    private function importAccountSettings(string $apiKey): void
    {
        try {
            $this->accountSettingsImporter->importFor($apiKey);
        } catch (Throwable $e) {
            Logger::warning('Could not import MyParcel account settings after an api key change.', LogContext::of($e));
            $this->messageManager->addWarningMessage(
                __(
                    'Your API key was saved, but the MyParcel account settings could not be imported: %1. Check the API key.',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Convert scope type string to Magento scope constant.
     *
     * @param string $scopeType
     * @return string
     */
    private function convertScope(string $scopeType): string
    {
        switch ($scopeType) {
            case 'websites':
            case 'website':
                return ScopeInterface::SCOPE_WEBSITES;
            case 'stores':
            case 'store':
                return ScopeInterface::SCOPE_STORES;
            default:
                return ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        }
    }

    /**
     * Reloads what this save wrote, and nothing else.
     *
     * reinit() is the same call Magento's own config save makes: it drops the merged config values —
     * cache tag `config_scopes` — and pre-warms them under a lock. cleanType('config') would empty
     * the whole cache type instead, discarding the merged system.xml structure that lives in it, and
     * every following request would rebuild that from every module's system.xml.
     *
     * Capability entries are keyed on the api key and expire on their own, so only an api key change
     * needs to drop them; dropping them for any other setting buys an API round trip.
     *
     * Rendered output can still hold a stale setting, so those types are marked invalid rather than
     * flushed: a flag write costs nothing, and the admin gets Magento's own invalidated-cache notice
     * to refresh them when it suits.
     */
    private function reinitConfig(bool $apiKeyChanged): void
    {
        $this->appConfig->reinit();

        if ($apiKeyChanged) {
            $this->cacheTypeList->cleanType(CapabilitiesCache::TYPE_IDENTIFIER);
        }

        $this->cacheTypeList->invalidate(['block_html', 'full_page']);
    }
}
