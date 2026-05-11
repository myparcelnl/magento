<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

class ProxyRouter implements RouterInterface
{
    private const PATH_MARKER = '/myparcel/proxy/';

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly string $actionClass
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $pathInfo = (string) $request->getPathInfo();
        $pos = strpos($pathInfo, self::PATH_MARKER);
        if ($pos === false) {
            return null;
        }

        $upstreamPath = ltrim(substr($pathInfo, $pos + strlen(self::PATH_MARKER)), '/');
        if ($upstreamPath === '') {
            return null;
        }

        $request->setParam('upstream_path', $upstreamPath);
        $request->setModuleName('myparcel')
                ->setControllerName('proxy')
                ->setActionName('forward');

        return $this->actionFactory->create($this->actionClass);
    }
}
