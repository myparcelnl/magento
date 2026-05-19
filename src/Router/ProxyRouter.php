<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use MyParcelNL\Magento\Service\Proxy\ProxyConfig;

/**
 * Routes storefront URLs under `/myparcel/proxy/<host>[/acceptance]/<path>`
 * to the configured action (the {@see \MyParcelNL\Magento\Controller\Proxy\Forward}
 * controller, wired in `etc/frontend/di.xml`).
 *
 * The first segment after `/myparcel/proxy/` is the upstream host (e.g.
 * `core`, `address`, `iam`). If the next segment is the literal
 * `acceptance`, it is consumed as an environment flag; the remainder is
 * the upstream path. The three values are exposed on the request as
 * `upstream_host`, `upstream_acceptance`, and `upstream_path`. No
 * security policy lives here — host registry and per-host path
 * allow-list are enforced in {@see \MyParcelNL\Magento\Service\Proxy\Client}.
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

        $remainder = ltrim(substr($pathInfo, $pos + strlen(self::PATH_MARKER)), '/');
        if ($remainder === '') {
            return null;
        }

        [$upstreamHost, $rest] = array_pad(explode('/', $remainder, 2), 2, '');
        if ($upstreamHost === '' || $rest === '') {
            return null;
        }

        $acceptance = false;
        $prefix     = ProxyConfig::ACCEPTANCE_SEGMENT . '/';
        if (strpos($rest, $prefix) === 0) {
            $acceptance = true;
            $rest       = substr($rest, strlen($prefix));
        }
        if ($rest === '') {
            return null;
        }

        $request->setParam('upstream_host', $upstreamHost);
        $request->setParam('upstream_acceptance', $acceptance);
        $request->setParam('upstream_path', $rest);
        $request->setModuleName('myparcel')
                ->setControllerName('proxy')
                ->setActionName('forward');

        return $this->actionFactory->create($this->actionClass);
    }
}
