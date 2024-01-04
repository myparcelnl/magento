<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Hooks;

use Magento\Framework\App\Action\Action;

class MagentoAbstractHook extends Action
{
    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @param $context
     * @param $messageManager
     */
    public function __construct(
        $context,
        $messageManager
    )
    {
        $this->messageManager = $messageManager;
        //parent::__construct();
    }

    /**
     * @return void
     */
    public function execute(): void
    {
    }
}
