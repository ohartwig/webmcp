<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Service;

use Koh\WebMcp\Application\Service\ToolInvocationLogger;
use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Koh\WebMcp\Domain\Model\ToolInvocationLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\ErrorLoggerInterface;

final class ToolInvocationLoggerTest extends TestCase
{
    #[Test]
    public function logCallsRepositorySave(): void
    {
        $repository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(ToolInvocationLog::class));

        $logger = new ToolInvocationLogger($repository, $this->createMock(ErrorLoggerInterface::class));

        $logger->log(
            toolName: 'search',
            userAgent: 'Test/1.0',
            ipHash: 'hash',
            siteIdentifier: 'main',
            rootPageId: 1,
            success: true,
            durationMs: 42,
        );
    }

    #[Test]
    public function logCatchesRepositoryException(): void
    {
        $repository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
        $repository->method('save')->willThrowException(new \RuntimeException('DB error'));

        $logger = new ToolInvocationLogger($repository, $this->createMock(ErrorLoggerInterface::class));

        // Should not throw
        $logger->log(
            toolName: 'search',
            userAgent: 'Test/1.0',
            ipHash: 'hash',
            siteIdentifier: 'main',
            rootPageId: 1,
            success: true,
            durationMs: 0,
        );

        self::assertTrue(true); // No exception thrown
    }
}
