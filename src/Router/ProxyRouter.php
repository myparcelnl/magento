<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Routes storefront URLs under `/myparcel/proxy/<upstream-path>` to the
 * configured action (the {@see \MyParcelNL\Magento\Controller\Proxy\Forward}
 * controller, wired in `etc/frontend/di.xml`).
 *
 * Extracts the upstream path from the URL and exposes it on the request
 * as the `upstream_path` param. No security policy lives here — that is
 * enforced once, in {@see \MyParcelNL\Magento\Service\Proxy\Client}.
 */
class ProxyRouter implements RouterInterface
{
    private const PATH_MARKER = '/myparcel/proxy/';

    private ActionFactory $actionFactory;
    private string $actionClass;

    public function __construct(ActionFactory $actionFactory, string $actionClass)
    {
        $this->actionFactory = $actionFactory;
        $this->actionClass = $actionClass;
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
