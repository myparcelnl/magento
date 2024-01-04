<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Service;

use Magento\Framework\Locale\Resolver;
use MyParcelNL\Pdk\Base\FileSystemInterface;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Pdk\Language\Repository\LanguageRepository;
use MyParcelNL\Pdk\Language\Service\AbstractLanguageService;

class LanguageService extends AbstractLanguageService
{
    /**
     * @var \Magento\Store\Api\Data\StoreInterface
     */
    private $store;

    public function __construct(
        Resolver            $store,
        LanguageRepository  $languageRepository,
        FileSystemInterface $fileSystem
    ) {
        parent::__construct($languageRepository, $fileSystem);

        $this->store = $store;
    }

    /**
     * @param  string|null $language
     *
     * @return string
     */
    public function getIso2(string $language = null): string
    {
        return substr($language ?? $this->getLanguage(), 0, 2);
    }

    /**
     * @return string
     */
    public function getLanguage(): string
    {
        return str_replace('_', '-', $this->store->getLocale());
    }

    /**
     * @param  null|string $language
     *
     * @return string
     */
    protected function getFilePath(?string $language = null): string
    {
        return sprintf('%s/config/pdk/translations/%s.json', Pdk::getAppInfo()->path, $this->getIso2($language));
    }
}
