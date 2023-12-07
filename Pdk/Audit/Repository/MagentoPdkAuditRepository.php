<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Audit\Repository;

use MyParcelNL\Pdk\Audit\Collection\AuditCollection;
use MyParcelNL\Pdk\Audit\Contract\PdkAuditRepositoryInterface;
use MyParcelNL\Pdk\Audit\Model\Audit;
use MyParcelNL\Pdk\Base\Repository\Repository;

class MagentoPdkAuditRepository extends Repository implements PdkAuditRepositoryInterface
{
    public function all(): AuditCollection
    {
        // TODO: Implement all() method.
    }

    public function store(Audit $audit): void
    {
        // TODO: Implement store() method.
    }
}
