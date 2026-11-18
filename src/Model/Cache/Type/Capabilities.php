<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/** Cache type for capability answers, so `bin/magento cache:clean` and the admin cache page can flush them. */
class Capabilities extends TagScope
{
    public const TYPE_IDENTIFIER = 'myparcelnl_capabilities';
    public const CACHE_TAG       = 'MYPARCELNL_CAPABILITIES';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
