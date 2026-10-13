<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Adapter;

use Koh\WebMcp\Infrastructure\Adapter\PsrErrorLogger;
use Koh\WebMcp\Infrastructure\Adapter\PsrEventDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Both adapters bind an application port to its framework counterpart. There is
 * no logic to speak of, but the wiring is worth holding in place: a dropped
 * context array would silently strip the diagnostics off every error, and an
 * event dispatcher that returns the event it was given instead of the one the
 * listeners saw would discard every modification made to it.
 */
final class PortAdapterTest extends TestCase
{
    #[Test]
    public function errorsArePassedToPsrLoggerWithTheirContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('tool invocation failed', ['tool' => 'search', 'reason' => 'timeout']);

        new PsrErrorLogger($logger)->error('tool invocation failed', ['tool' => 'search', 'reason' => 'timeout']);
    }

    #[Test]
    public function contextDefaultsToAnEmptyArray(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('something broke', []);

        new PsrErrorLogger($logger)->error('something broke');
    }

    /**
     * Listeners may replace or mutate the event, so the dispatcher has to hand
     * back what the PSR dispatcher returned rather than the original object.
     */
    #[Test]
    public function theDispatchedEventIsReturnedRatherThanTheOriginal(): void
    {
        $given = new \stdClass();
        $returned = new \stdClass();

        $psr = $this->createMock(PsrEventDispatcherInterface::class);
        $psr->expects(self::once())->method('dispatch')->with($given)->willReturn($returned);

        self::assertSame($returned, new PsrEventDispatcher($psr)->dispatch($given));
    }
}
