<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Event;

use Koh\WebMcp\Application\Event\ToolExecutedEvent;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolExecutedEventTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $result = ToolResult::success(['found' => true]);
        $context = new ExecutionContext(rootPageId: 1);

        $event = new ToolExecutedEvent(
            toolName: 'search',
            result: $result,
            context: $context,
            durationMs: 42,
        );

        self::assertSame('search', $event->toolName);
        self::assertSame($result, $event->result);
        self::assertSame($context, $event->context);
        self::assertSame(42, $event->durationMs);
    }
}
