<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Command;

use Koh\WebMcp\Application\Contract\LogRetentionInterface;
use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Koh\WebMcp\Infrastructure\Command\CleanupToolLogCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * This command deletes log rows, so the retention window it computes is the
 * thing worth pinning down: an off-by-one or a silently accepted 0 would erase
 * more history than intended, and there is no undo.
 */
final class CleanupToolLogCommandTest extends TestCase
{
    private ToolInvocationLogRepositoryInterface&MockObject $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
    }

    private function tester(int $configuredDays = 30): CommandTester
    {
        $retention = $this->createMock(LogRetentionInterface::class);
        $retention->method('days')->willReturn($configuredDays);

        return new CommandTester(new CleanupToolLogCommand($this->repository, $retention));
    }

    /**
     * Without --days the command deletes by the configured retention, so the
     * scheduled run and the opportunistic purge in the API agree on one value.
     */
    #[Test]
    public function withoutAnOptionTheConfiguredRetentionIsUsed(): void
    {
        $this->repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(function (\DateTimeImmutable $cutoff): bool {
                $expected = new \DateTimeImmutable('-14 days');

                return abs($cutoff->getTimestamp() - $expected->getTimestamp()) <= 1;
            }))
            ->willReturn(3);

        $tester = $this->tester(14);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Deleted 3 log entries older than 14 days.', $tester->getDisplay());
    }

    #[Test]
    public function defaultRetentionIsThirtyDays(): void
    {
        $this->repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(function (\DateTimeImmutable $cutoff): bool {
                $expected = new \DateTimeImmutable('-30 days');

                // A second of slack: the command builds its own "now".
                return abs($cutoff->getTimestamp() - $expected->getTimestamp()) <= 1;
            }))
            ->willReturn(7);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Deleted 7 log entries older than 30 days.', $tester->getDisplay());
    }

    #[Test]
    public function anExplicitRetentionWindowIsUsed(): void
    {
        $this->repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(function (\DateTimeImmutable $cutoff): bool {
                $expected = new \DateTimeImmutable('-7 days');

                return abs($cutoff->getTimestamp() - $expected->getTimestamp()) <= 1;
            }))
            ->willReturn(0);

        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--days' => '7']));
        self::assertStringContainsString('Deleted 0 log entries older than 7 days.', $tester->getDisplay());
    }

    /**
     * Zero would mean "delete everything up to this instant", and a negative
     * value would put the cutoff in the future and wipe the table outright.
     * Both have to be refused before the repository is ever reached.
     */
    #[Test]
    public function aRetentionBelowOneDayIsRefused(): void
    {
        $this->repository->expects(self::never())->method('deleteOlderThan');

        foreach (['0', '-1'] as $days) {
            $tester = $this->tester();

            self::assertSame(1, $tester->execute(['--days' => $days]), sprintf('--days=%s must fail', $days));
            self::assertStringContainsString('Days must be at least 1', $tester->getDisplay());
        }
    }

    /**
     * The option arrives as a string. Anything non-numeric casts to 0, which
     * the guard then has to catch rather than deleting the whole log.
     */
    #[Test]
    public function aNonNumericRetentionIsRefused(): void
    {
        $this->repository->expects(self::never())->method('deleteOlderThan');

        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['--days' => 'forever']));
        self::assertStringContainsString('Days must be at least 1', $tester->getDisplay());
    }
}
