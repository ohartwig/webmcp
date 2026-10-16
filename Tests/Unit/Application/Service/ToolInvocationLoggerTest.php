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

    #[Test]
    public function purgeDeletesByTheGivenRetentionAndReportsTheCount(): void
    {
        $repository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(function (\DateTimeImmutable $cutoff): bool {
                $expected = new \DateTimeImmutable('-7 days');

                return abs($cutoff->getTimestamp() - $expected->getTimestamp()) <= 1;
            }))
            ->willReturn(5);

        $logger = new ToolInvocationLogger($repository, $this->createMock(ErrorLoggerInterface::class));

        self::assertSame(5, $logger->purgeOlderThan(7));
    }

    /**
     * A retention below one day would read as "delete everything"; the
     * logger refuses to pass that on.
     */
    #[Test]
    public function purgeNeverUsesARetentionBelowOneDay(): void
    {
        $repository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(function (\DateTimeImmutable $cutoff): bool {
                $expected = new \DateTimeImmutable('-1 days');

                return abs($cutoff->getTimestamp() - $expected->getTimestamp()) <= 1;
            }))
            ->willReturn(0);

        new ToolInvocationLogger($repository, $this->createMock(ErrorLoggerInterface::class))->purgeOlderThan(0);
    }

    #[Test]
    public function purgeSwallowsRepositoryFailures(): void
    {
        $repository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
        $repository->method('deleteOlderThan')->willThrowException(new \RuntimeException('DB error'));
        $errors = $this->createMock(ErrorLoggerInterface::class);
        $errors->expects(self::once())->method('error');

        self::assertSame(0, new ToolInvocationLogger($repository, $errors)->purgeOlderThan(30));
    }

    #[Test]
    public function maybePurgeAlwaysPurgesWhenTheOddsAreOneInOne(): void
    {
        $repository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
        $repository->expects(self::once())->method('deleteOlderThan')->willReturn(0);

        new ToolInvocationLogger($repository, $this->createMock(ErrorLoggerInterface::class))->maybePurge(30, 1);
    }
}
