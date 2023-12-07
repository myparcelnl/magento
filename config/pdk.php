<?php

declare(strict_types=1);

use MyParcelNL\Magento\Facade\Magento;
use MyParcelNL\Magento\Logger\MagentoLogger;
use MyParcelNL\Magento\Pdk\Plugin\Action\MagentoBackendEndpointService;
use MyParcelNL\Magento\Pdk\Plugin\Action\MagentoFrontendEndpointService;
use MyParcelNL\Magento\Pdk\Plugin\Action\MagentoWebhookService;
use MyParcelNL\Magento\Pdk\Plugin\Installer\MagentoMigrationService;
use MyParcelNL\Magento\Pdk\Plugin\MagentoShippingMethodRepository;
use MyParcelNL\Magento\Pdk\Plugin\Repository\MagentoCartRepository;
use MyParcelNL\Magento\Pdk\Plugin\Repository\PdkAccountRepository;
use MyParcelNL\Magento\Pdk\Plugin\Repository\PdkOrderRepository;
use MyParcelNL\Magento\Pdk\Plugin\Service\MagentoDeliveryOptionsService;
use MyParcelNL\Magento\Pdk\Plugin\Service\MagentoStatusService;
use MyParcelNL\Magento\Pdk\Service\LanguageService;
use MyParcelNL\Magento\Pdk\Service\MagentoViewService;
use MyParcelNL\Magento\Pdk\Settings\Repository\PdkSettingsRepository;
use MyParcelNL\Magento\Pdk\Webhook\MagentoWebhooksRepository;
use MyParcelNL\Magento\Service\MagentoCronService;
use MyParcelNL\Magento\Service\MagentoScriptService;
use MyParcelNL\Pdk\Api\Contract\ClientAdapterInterface;
use MyParcelNL\Pdk\App\Account\Contract\PdkAccountRepositoryInterface;
use MyParcelNL\Pdk\App\Api\Contract\BackendEndpointServiceInterface;
use MyParcelNL\Pdk\App\Api\Contract\FrontendEndpointServiceInterface;
use MyParcelNL\Pdk\App\Cart\Contract\PdkCartRepositoryInterface;
use MyParcelNL\Pdk\App\DeliveryOptions\Contract\DeliveryOptionsServiceInterface;
use MyParcelNL\Pdk\App\Installer\Contract\MigrationServiceInterface;
use MyParcelNL\Pdk\App\Order\Contract\OrderStatusServiceInterface;
use MyParcelNL\Pdk\App\Order\Contract\PdkOrderRepositoryInterface;
use MyParcelNL\Pdk\App\ShippingMethod\Contract\PdkShippingMethodRepositoryInterface;
use MyParcelNL\Pdk\App\Webhook\Contract\PdkWebhookServiceInterface;
use MyParcelNL\Pdk\App\Webhook\Contract\PdkWebhooksRepositoryInterface;
use MyParcelNL\Pdk\Base\Contract\CronServiceInterface;
use MyParcelNL\Pdk\Base\Support\Arr;
use MyParcelNL\Pdk\Facade\Language;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Pdk\Facade\Pdk as PdkFacade;
use MyParcelNL\Pdk\Facade\Settings;
use MyParcelNL\Pdk\Frontend\Contract\ScriptServiceInterface;
use MyParcelNL\Pdk\Frontend\Contract\ViewServiceInterface;
use MyParcelNL\Pdk\Language\Contract\LanguageServiceInterface;
use MyParcelNL\Pdk\Settings\Contract\PdkSettingsRepositoryInterface;
use MyParcelNL\Pdk\Settings\Model\OrderSettings;
use Psr\Log\LoggerInterface;
use function DI\factory;
use function DI\value;
use function DI\get;

return [

    'appInfo' => value([
        'name'    => 'MyParcel',
        'title'   => 'MyParcel',
        'path'    => dirname(__FILE__, 3),
        'url'     => dirname(__FILE__, 3),
        'version' => '2.3.5',
    ]),

    'userAgent' => factory(function (): array {
        return [
            'MyParcelNL-Magento2' => Pdk::getAppInfo()->version,
            'Magento'             => Magento::getVersion(),
        ];
    }),

    'bulkActions' => factory(static function (): array {
        $orderModeEnabled = Settings::get(OrderSettings::ORDER_MODE, OrderSettings::ID);
        $all              = PdkFacade::get('allBulkActions');

        return $orderModeEnabled
            ? Arr::get($all, 'orderMode', [])
            : Arr::get($all, 'default', []);
    }),

    'defaultSettings'        => value([]),

    /**
     * Error message to show when the current php version is not supported.
     */
    'errorMessagePhpVersion' => factory(function (): string {
        return strtr(Language::translate('error_prerequisites_php_version'), [
            '{name}'    => Pdk::getAppInfo()->title,
            '{version}' => Pdk::get('minimumPhpVersion'),
            '{current}' => PHP_VERSION,
        ]);
    }),

    /**
     * Repositories
     */

    PdkAccountRepositoryInterface::class        => get(PdkAccountRepository::class),
    PdkOrderRepositoryInterface::class          => get(PdkOrderRepository::class),
    PdkSettingsRepositoryInterface::class       => get(PdkSettingsRepository::class),
    PdkCartRepositoryInterface::class           => get(MagentoCartRepository::class),
    PdkShippingMethodRepositoryInterface::class => get(MagentoShippingMethodRepository::class),

    /**
     * Services
     */

    CronServiceInterface::class        => get(MagentoCronService::class),
    LanguageServiceInterface::class    => get(LanguageService::class),
    OrderStatusServiceInterface::class => get(MagentoStatusService::class),
    ViewServiceInterface::class        => get(MagentoViewService::class),

    /**
     * Endpoints
     */

    BackendEndpointServiceInterface::class  => get(MagentoBackendEndpointService::class),
    FrontendEndpointServiceInterface::class => get(MagentoFrontendEndpointService::class),

    /**
     * Webhooks
     */

    PdkWebhookServiceInterface::class     => get(MagentoWebhookService::class),
    PdkWebhooksRepositoryInterface::class => get(MagentoWebhooksRepository::class),

    /**
     * Miscellaneous
     */

    ClientAdapterInterface::class          => get(GuzzleHttp\Client::class),
    DeliveryOptionsServiceInterface::class => get(MagentoDeliveryOptionsService::class),
    LoggerInterface::class                 => get(MagentoLogger::class),
    MigrationServiceInterface::class       => get(MagentoMigrationService::class),
    ScriptServiceInterface::class          => get(MagentoScriptService::class),

];
