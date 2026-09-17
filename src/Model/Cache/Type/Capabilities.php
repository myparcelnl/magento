<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Cache type for capability answers, so they are flushable from `bin/magento cache:clean` and the
 * admin cache page.
 *
 * A dedicated type rather than configuration storage: the key space is the product of account,
 * country, weight, package type, delivery type, direction and options, and high-cardinality derived
 * data in core_config_data would invalidate the config cache on every write and show up in config
 * dumps.
 */
class Capabilities extends TagScope
{
    public const TYPE_IDENTIFIER = 'myparcelnl_capabilities';
    public const CACHE_TAG       = 'MYPARCELNL_CAPABILITIES';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
