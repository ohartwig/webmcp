<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Service;

use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Koh\WebMcp\Domain\Model\ToolInvocationLog;
use Koh\WebMcp\Application\Contract\ErrorLoggerInterface;

final readonly class ToolInvocationLogger
{
    public function __construct(
        private ToolInvocationLogRepositoryInterface $repository,
        private ErrorLoggerInterface $logger,
    ) {}

    public function log(
        string $toolName,
        string $userAgent,
        string $ipHash,
        string $siteIdentifier,
        int $rootPageId,
        bool $success,
        int $durationMs,
        string $errorMessage = '',
    ): void {
        $entry = new ToolInvocationLog(
            toolName: $toolName,
            timestamp: new \DateTimeImmutable(),
            userAgent: $userAgent,
            ipHash: $ipHash,
            siteIdentifier: $siteIdentifier,
            rootPageId: $rootPageId,
            success: $success,
            durationMs: $durationMs,
            errorMessage: $errorMessage,
        );

        try {
            $this->repository->save($entry);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save tool invocation log', [
                'tool' => $toolName,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
