<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk;

use MyParcelNL\Pdk\Base\PdkBootstrapper;
use MyParcelNL\Pdk\Facade\Pdk;
use function DI\factory;
use function DI\value;

class MagentoPdkBootstrapper extends PdkBootstrapper
{
    /**
     * @var array
     */
    private static $config = [];

    /**
     * @param  string $name
     * @param  string $title
     * @param  string $version
     * @param  string $path
     * @param  string $url
     *
     * @return array
     */
    protected function getAdditionalConfig(
        string $name,
        string $title,
        string $version,
        string $path,
        string $url
    ): array {
        return array_replace(self::$config, [
            ###
            # General
            ###

            'pluginBasename' => value('MyParcelNL_Magento'),

            'urlDocumentation' => value('https://developer.myparcel.nl/nl/documentatie/13.magento2.html'),
            'urlReleaseNotes'  => value('https://github.com/myparcelnl/magento/releases'),

            'defaultWeightUnit' => value('kg'),

            'addressTypeBilling'  => value('billing'),
            'addressTypeShipping' => value('shipping'),

            'addressTypes' => factory(static function (): array {
                return [
                    Pdk::get('addressTypeBilling'),
                    Pdk::get('addressTypeShipping'),
                ];
            }),
        ]);
    }
}
