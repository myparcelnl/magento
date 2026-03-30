<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Resource;

use MyParcelNL\Magento\Model\Rest\AbstractVersionedResource;

class OrderDeliveryOptionsV1Resource extends AbstractVersionedResource
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function getVersion(): int
    {
        return 1;
    }

    public function format(): array
    {
        return $this->data;
    }
}
