<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Plugin\Repository;

use MyParcelNL\Pdk\App\Order\Collection\PdkOrderNoteCollection;
use MyParcelNL\Pdk\App\Order\Model\PdkOrder;
use MyParcelNL\Pdk\App\Order\Model\PdkOrderNote;
use MyParcelNL\Pdk\App\Order\Repository\AbstractPdkOrderNoteRepository;

class MagentoOrderNoteRepository extends AbstractPdkOrderNoteRepository
{
    public function add(PdkOrderNote $note): void
    {
        // TODO: Implement add() method.
    }

    public function getFromOrder(PdkOrder $order): PdkOrderNoteCollection
    {
        // TODO: Implement getFromOrder() method.
    }

    public function update(PdkOrderNote $note): void
    {
        // TODO: Implement update() method.
    }
}
