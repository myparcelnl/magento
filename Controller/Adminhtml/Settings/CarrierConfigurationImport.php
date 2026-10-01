<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\AccountSettings\Maintenance as AccountSettingsMaintenance;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Sdk\Exception\AccountNotActiveException;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Exception\ValidationException;
use Throwable;

class CarrierConfigurationImport extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MyParcelNL_Magento::myparcelnl_magento_settings';

    private const ALLOWED_SCOPES = [
        ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        ScopeInterface::SCOPE_WEBSITES,
        ScopeInterface::SCOPE_STORES,
    ];

    private ScopeConfigInterface      $config;
    private StoreManagerInterface     $storeManager;
    private ReinitableConfigInterface $appConfig;
    private TypeListInterface         $typeListInterface;
    private Importer                   $importer;
    private AccountSettingsMaintenance $accountSettingsMaintenance;

    public function __construct(
        Context                    $context,
        ScopeConfigInterface       $config,
        JsonFactory                $resultFactory,
        TypeListInterface          $typeListInterface,
        ReinitableConfigInterface  $appConfig,
        Importer                   $importer,
        AccountSettingsMaintenance $accountSettingsMaintenance,
        StoreManagerInterface      $storeManager
    )
    {
        parent::__construct($context);

        $this->config                     = $config;
        $this->resultFactory              = $resultFactory;
        $this->typeListInterface          = $typeListInterface;
        $this->appConfig                  = $appConfig;
        $this->importer                   = $importer;
        $this->accountSettingsMaintenance = $accountSettingsMaintenance;
        $this->storeManager               = $storeManager;
    }

    /**
     * Answers the button rather than throwing. An invalid key is the common failure here, and a 500
     * tells the admin nothing they can act on.
     */
    public function execute()
    {
        try {
            $changed = $this->importer->importFor($this->requestedApiKey());
        } catch (LocalizedException $e) {
            return $this->failure($e->getMessage());
        } catch (AccountNotActiveException|ApiException|MissingFieldException|ValidationException $e) {
            Logger::warning('Could not import MyParcel account settings.', LogContext::of($e));

            return $this->failure($e->getMessage());
        } catch (Throwable $e) {
            // Anything else is a bug, not a message an admin can act on: a TypeError or a DB error
            // would otherwise put its own internals in the button's response.
            Logger::critical('Unexpected error importing MyParcel account settings.', LogContext::of($e));

            return $this->failure((string) __('An unexpected error occurred. Please check the log.'));
        }

        $this->reloadConfig();
        // After the reload, because it reads config.
        $this->accountSettingsMaintenance->reconcile();
        // The page reloads when the row changed, so the message waits in the session.
        if ($changed) {
            $this->messageManager->addSuccessMessage(__('MyParcel Backoffice settings imported.'));
        }

        return $this->resultFactory->create()
                                   ->setData(
                                       [
                                           'success' => true,
                                           'changed' => $changed,
                                           'time'    => date('Y-m-d H:i:s'),
                                       ]
                                   )
        ;
    }

    /**
     * Resolves the API key of the scope the request names.
     *
     * Read here, not in the constructor: _isAllowed() runs in dispatch(), so a constructor lookup
     * would resolve another scope's key before the ACL check.
     *
     * @throws LocalizedException when the scope is not a real one, or names a website or store that
     *                            does not exist
     */
    private function requestedApiKey(): string
    {
        $scope = (string) $this->getRequest()
            ->getParam('scope', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new LocalizedException(__('Unknown configuration scope "%1".', $scope));
        }

        $scopeId = $this->validatedScopeId($scope, $this->getRequest()->getParam('scopeId', 0));

        return (string) $this->config->getValue(Config::XML_PATH_API_KEY, $scope, $scopeId);
    }

    /**
     * @param  int|string $scopeId
     * @throws LocalizedException
     */
    private function validatedScopeId(string $scope, $scopeId): int
    {
        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scope) {
            return 0;
        }

        if (! is_numeric($scopeId)) {
            throw new LocalizedException(__('Configuration scope id must be a number.'));
        }

        $scopeId   = (int) $scopeId;
        $isWebsite = ScopeInterface::SCOPE_WEBSITES === $scope;

        try {
            $isWebsite
                ? $this->storeManager->getWebsite($scopeId)
                : $this->storeManager->getStore($scopeId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Configuration scope "%1" has no id %2.', $scope, $scopeId));
        }

        return $scopeId;
    }

    /** The shape the settings button expects on a failed import. */
    private function failure(string $message): ResultInterface
    {
        return $this->resultFactory->create()->setData(['success' => false, 'message' => $message]);
    }

    /** Reload what the import wrote, as ConfigChange::reinitConfig() does after a settings save. */
    private function reloadConfig(): void
    {
        $this->appConfig->reinit();
        // The import replaces the account's contract, which the cached capabilities were read under.
        $this->typeListInterface->cleanType(CapabilitiesCache::TYPE_IDENTIFIER);
        $this->typeListInterface->invalidate(['block_html', 'full_page']);
    }
}
