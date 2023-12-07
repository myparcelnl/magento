<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Hooks;

use MyParcelNL\Magento\Pdk\Hooks\Contract\MagentoHooksInterface;

final class MessageManagerHook extends MagentoAbstractHook implements MagentoHooksInterface
{
    public const MESSAGE_SUCCESS = 'addSuccess';
    public const MESSAGE_ERROR   = 'addError';
    public const MESSAGE_WARNING = 'addWarning';
    public const MESSAGE_NOTICE  = 'addNotice';

    /**
     * @param  null|array $data
     *
     * @return void
     */
    public function apply(?array $data): void
    {
        $this->addMessage(
            $data['type'],
            $data['message']
        );
    }

    /**
     * @param string $type
     * @param string $message
     *
     * @return void
     */
    public function addMessage(string $type, string $message): void
    {
        $this->messageManager->{$type}(__($message));
    }
}
