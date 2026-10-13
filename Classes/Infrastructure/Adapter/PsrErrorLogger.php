<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Adapter;

use Koh\WebMcp\Application\Contract\ErrorLoggerInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds the application's error-reporting port to the framework's PSR-3 logger.
 */
final readonly class PsrErrorLogger implements ErrorLoggerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
