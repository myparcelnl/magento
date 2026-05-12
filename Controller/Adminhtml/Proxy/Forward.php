<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Proxy;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpDeleteActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpHeadActionInterface;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\Action\HttpPatchActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpPutActionInterface;
use Magento\Framework\Controller\ResultInterface;
use MyParcelNL\Magento\Service\Proxy\Forwarder;

class Forward extends Action implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    HttpPutActionInterface,
    HttpDeleteActionInterface,
    HttpPatchActionInterface,
    HttpOptionsActionInterface
{
    public const ADMIN_RESOURCE = 'MyParcelNL_Magento::api_proxy';

    /**
     * The upstream path lives in the URL tail, not in the action name. Mark the
     * action as public so the standard admin URL-key check is skipped (admin
     * session + ACL still gate access; form key still enforced on non-GET).
     *
     * @var string[]
     */
    protected $_publicActions = ['forward'];

    private Forwarder $forwarder;

    public function __construct(Context $context, Forwarder $forwarder)
    {
        parent::__construct($context);
        $this->forwarder = $forwarder;
    }

    public function execute(): ResultInterface
    {
        return $this->forwarder->forward($this->getRequest());
    }
}
