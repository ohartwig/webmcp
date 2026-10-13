<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Service;

use Koh\WebMcp\Application\Event\CollectContextsEvent;
use Koh\WebMcp\Application\Service\ContextRegistry;
use Koh\WebMcp\Domain\Contract\ContextProviderInterface;
use Koh\WebMcp\Domain\Model\ContextDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;

final class ContextRegistryTest extends TestCase
{
    #[Test]
    public function getContextsCollectsFromAllProviders(): void
    {
        $context1 = new ContextDefinition(name: 'page-meta', description: 'Meta', data: []);
        $context2 = new ContextDefinition(name: 'site-info', description: 'Site', data: []);

        $provider1 = $this->createMock(ContextProviderInterface::class);
        $provider1->method('getContexts')->willReturn([$context1]);

        $provider2 = $this->createMock(ContextProviderInterface::class);
        $provider2->method('getContexts')->willReturn([$context2]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        $registry = new ContextRegistry([$provider1, $provider2], $dispatcher);
        $contexts = $registry->getContexts();

        self::assertCount(2, $contexts);
        self::assertSame('page-meta', $contexts[0]->name);
        self::assertSame('site-info', $contexts[1]->name);
    }

    #[Test]
    public function getContextsDispatchesEvent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CollectContextsEvent::class))
            ->willReturnCallback(fn($event) => $event);

        $registry = new ContextRegistry([], $dispatcher);
        $registry->getContexts();
    }

    #[Test]
    public function getContextToolsDerivesReadOnlyToolPerContext(): void
    {
        $registry = $this->registryWith(
            new ContextDefinition(name: 'page-meta', description: 'Meta of the page', data: ['title' => 'Home']),
            new ContextDefinition(name: 'site-info', description: 'Site', data: []),
        );

        $tools = $registry->getContextTools();

        self::assertCount(2, $tools);
        self::assertSame('get-page-meta', $tools[0]->name);
        self::assertSame('get-site-info', $tools[1]->name);
        self::assertSame('Meta of the page', $tools[0]->description);
        self::assertTrue($tools[0]->annotations->readOnlyHint);
        self::assertTrue($tools[0]->annotations->untrustedContentHint);
    }

    #[Test]
    public function getContextToolsUsesEmptyObjectInputSchema(): void
    {
        $registry = $this->registryWith(
            new ContextDefinition(name: 'page-meta', description: 'Meta', data: []),
        );

        $schema = $registry->getContextTools()[0]->inputSchema;

        self::assertSame('object', $schema['type']);
        // Must serialise as `{}`, not `[]` — an empty PHP array would produce
        // an invalid JSON Schema.
        self::assertSame('{"type":"object","properties":{}}', json_encode($schema));
    }

    #[Test]
    public function getContextToolsSkipsContextsWithInvalidToolName(): void
    {
        $registry = $this->registryWith(
            new ContextDefinition(name: 'page meta', description: 'Invalid', data: []),
            new ContextDefinition(name: 'site-info', description: 'Valid', data: []),
        );

        $tools = $registry->getContextTools();

        self::assertCount(1, $tools);
        self::assertSame('get-site-info', $tools[0]->name);
    }

    #[Test]
    public function findContextByToolNameResolvesDerivedName(): void
    {
        $context = new ContextDefinition(name: 'page-meta', description: 'Meta', data: ['title' => 'Home']);
        $registry = $this->registryWith($context);

        self::assertSame($context, $registry->findContextByToolName('get-page-meta'));
        self::assertNull($registry->findContextByToolName('page-meta'));
        self::assertNull($registry->findContextByToolName('get-unknown'));
    }

    private function registryWith(ContextDefinition ...$contexts): ContextRegistry
    {
        $provider = $this->createMock(ContextProviderInterface::class);
        $provider->method('getContexts')->willReturn($contexts);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        return new ContextRegistry([$provider], $dispatcher);
    }
}
