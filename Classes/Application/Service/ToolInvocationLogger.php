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

    /**
     * Deletes entries older than the retention. Returns the number removed,
     * or 0 when the repository failed — the error is logged, a tool call must
     * not fail because housekeeping did.
     */
    public function purgeOlderThan(int $days): int
    {
        $days = max(1, $days);

        try {
            return $this->repository->deleteOlderThan(new \DateTimeImmutable("-{$days} days"));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to purge tool invocation log', [
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Purges on roughly one call in $oneIn, so the retention holds on an
     * installation where nobody scheduled the cleanup command. The scheduled
     * command stays the proper way; this is the floor under it.
     */
    public function maybePurge(int $days, int $oneIn = 100): void
    {
        if (1 === random_int(1, max(1, $oneIn))) {
            $this->purgeOlderThan($days);
        }
    }
}
