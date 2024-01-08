<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Logger;

use MyParcelNL\Pdk\Logger\AbstractLogger;

class MagentoLogger extends AbstractLogger
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array  $context
     *
     * @return void
     * @throws \Psr\Log\InvalidArgumentException
     */
    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
