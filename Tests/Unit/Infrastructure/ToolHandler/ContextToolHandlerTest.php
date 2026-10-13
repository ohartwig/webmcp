<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolHandler;

use Koh\WebMcp\Application\Service\ContextRegistry;
use Koh\WebMcp\Domain\Contract\ContextProviderInterface;
use Koh\WebMcp\Domain\Model\ContextDefinition;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Infrastructure\ToolHandler\ContextToolHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;

final class ContextToolHandlerTest extends TestCase
{
    #[Test]
    public function getToolNamesReturnsDerivedNames(): void
    {
        $handler = $this->handlerWith(
            new ContextDefinition(name: 'page-meta', description: 'Meta', data: []),
            new ContextDefinition(name: 'site-info', description: 'Site', data: []),
        );

        self::assertSame(['get-page-meta', 'get-site-info'], $handler->getToolNames());
    }

    #[Test]
    public function executeReturnsContextData(): void
    {
        $handler = $this->handlerWith(
            new ContextDefinition(name: 'page-meta', description: 'Meta', data: ['title' => 'Home', 'pageId' => 1]),
        );

        $result = $handler->execute('get-page-meta', [], new ExecutionContext(rootPageId: 1));

        self::assertTrue($result->success);
        self::assertSame(['title' => 'Home', 'pageId' => 1], $result->data);
    }

    #[Test]
    public function executeIgnoresInput(): void
    {
        $handler = $this->handlerWith(
            new ContextDefinition(name: 'page-meta', description: 'Meta', data: ['title' => 'Home']),
        );

        $result = $handler->execute('get-page-meta', ['pageId' => 99], new ExecutionContext(rootPageId: 1));

        self::assertTrue($result->success);
        self::assertSame(['title' => 'Home'], $result->data);
    }

    #[Test]
    public function executeReturnsErrorForUnknownContext(): void
    {
        $handler = $this->handlerWith(
            new ContextDefinition(name: 'page-meta', description: 'Meta', data: []),
        );

        $result = $handler->execute('get-nope', [], new ExecutionContext(rootPageId: 1));

        self::assertFalse($result->success);
        self::assertSame('Unknown context', $result->error);
    }

    #[Test]
    public function getToolNamesIsEmptyWithoutContexts(): void
    {
        self::assertSame([], $this->handlerWith()->getToolNames());
    }

    private function handlerWith(ContextDefinition ...$contexts): ContextToolHandler
    {
        $provider = $this->createMock(ContextProviderInterface::class);
        $provider->method('getContexts')->willReturn($contexts);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        return new ContextToolHandler(new ContextRegistry([$provider], $dispatcher));
    }
}
