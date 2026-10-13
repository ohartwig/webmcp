<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Service;

use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Application\Event\ToolExecutedEvent;
use Koh\WebMcp\Application\Service\ToolExecutionService;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;
use Koh\WebMcp\Application\Contract\ErrorLoggerInterface;

final class ToolExecutionServiceTest extends TestCase
{
    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);
    }

    #[Test]
    public function executeReturnsErrorForUnknownTool(): void
    {
        $service = new ToolExecutionService([], $this->createMock(ErrorLoggerInterface::class), $this->dispatcher);
        $context = new ExecutionContext(rootPageId: 1);

        $result = $service->execute('unknown', [], $context);

        self::assertFalse($result->success);
        self::assertStringContainsString('Unknown tool', $result->error);
    }

    #[Test]
    public function executeDelegatesToCorrectHandler(): void
    {
        $handler = $this->createMock(ToolHandlerInterface::class);
        $handler->method('getToolName')->willReturn('search');
        $handler->method('execute')->willReturn(ToolResult::success(['found' => true]));

        $service = new ToolExecutionService([$handler], $this->createMock(ErrorLoggerInterface::class), $this->dispatcher);
        $context = new ExecutionContext(rootPageId: 1);

        $result = $service->execute('search', ['query' => 'test'], $context);

        self::assertTrue($result->success);
        self::assertSame(['found' => true], $result->data);
    }

    #[Test]
    public function hasHandlerReturnsTrueForRegisteredTool(): void
    {
        $handler = $this->createMock(ToolHandlerInterface::class);
        $handler->method('getToolName')->willReturn('search');

        $service = new ToolExecutionService([$handler], $this->createMock(ErrorLoggerInterface::class), $this->dispatcher);

        self::assertTrue($service->hasHandler('search'));
        self::assertFalse($service->hasHandler('unknown'));
    }

    #[Test]
    public function duplicateHandlerThrowsException(): void
    {
        $handler1 = $this->createMock(ToolHandlerInterface::class);
        $handler1->method('getToolName')->willReturn('search');

        $handler2 = $this->createMock(ToolHandlerInterface::class);
        $handler2->method('getToolName')->willReturn('search');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate tool handler for "search"');

        new ToolExecutionService([$handler1, $handler2], $this->createMock(ErrorLoggerInterface::class), $this->dispatcher);
    }

    #[Test]
    public function executeReturnsInternalErrorOnException(): void
    {
        $handler = $this->createMock(ToolHandlerInterface::class);
        $handler->method('getToolName')->willReturn('search');
        $handler->method('execute')->willThrowException(new \RuntimeException('DB error'));

        $service = new ToolExecutionService([$handler], $this->createMock(ErrorLoggerInterface::class), $this->dispatcher);
        $context = new ExecutionContext(rootPageId: 1);

        $result = $service->execute('search', [], $context);

        self::assertFalse($result->success);
        self::assertSame('Internal error', $result->error);
    }

    #[Test]
    public function executeDispatchesToolExecutedEvent(): void
    {
        $handler = $this->createMock(ToolHandlerInterface::class);
        $handler->method('getToolName')->willReturn('search');
        $handler->method('execute')->willReturn(ToolResult::success([]));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(ToolExecutedEvent::class))
            ->willReturnCallback(fn($event) => $event);

        $service = new ToolExecutionService([$handler], $this->createMock(ErrorLoggerInterface::class), $dispatcher);
        $context = new ExecutionContext(rootPageId: 1);

        $service->execute('search', [], $context);
    }

    #[Test]
    public function executeEventContainsDuration(): void
    {
        $handler = $this->createMock(ToolHandlerInterface::class);
        $handler->method('getToolName')->willReturn('search');
        $handler->method('execute')->willReturn(ToolResult::success([]));

        $capturedEvent = null;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$capturedEvent) {
            $capturedEvent = $event;

            return $event;
        });

        $service = new ToolExecutionService([$handler], $this->createMock(ErrorLoggerInterface::class), $dispatcher);
        $context = new ExecutionContext(rootPageId: 1);

        $service->execute('search', [], $context);

        self::assertInstanceOf(ToolExecutedEvent::class, $capturedEvent);
        self::assertGreaterThanOrEqual(0, $capturedEvent->durationMs);
        self::assertSame('search', $capturedEvent->toolName);
    }
}
