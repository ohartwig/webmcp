<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Service;

use Koh\WebMcp\Application\Event\CollectToolsEvent;
use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\PageToolConfiguration;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;

final class ToolRegistryTest extends TestCase
{
    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);
    }

    #[Test]
    public function getToolsCollectsFromAllProviders(): void
    {
        $tool1 = new ToolDefinition(name: 'search', description: 'Search', inputSchema: []);
        $tool2 = new ToolDefinition(name: 'navigation', description: 'Navigate', inputSchema: []);

        $provider1 = $this->createMock(ToolProviderInterface::class);
        $provider1->method('getTools')->willReturn([$tool1]);

        $provider2 = $this->createMock(ToolProviderInterface::class);
        $provider2->method('getTools')->willReturn([$tool2]);

        $registry = new ToolRegistry([$provider1, $provider2], $this->dispatcher);
        $tools = $registry->getTools();

        self::assertCount(2, $tools);
        self::assertSame('search', $tools[0]->name);
        self::assertSame('navigation', $tools[1]->name);
    }

    #[Test]
    public function getToolsFiltersDisabledTools(): void
    {
        $tool = new ToolDefinition(name: 'search', description: 'Search', inputSchema: []);

        $provider = $this->createMock(ToolProviderInterface::class);
        $provider->method('getTools')->willReturn([$tool]);

        $registry = new ToolRegistry([$provider], $this->dispatcher);
        $tools = $registry->getTools(['tools' => ['search' => ['enabled' => false]]]);

        self::assertCount(0, $tools);
    }

    #[Test]
    public function getToolsDispatchesCollectToolsEvent(): void
    {
        $provider = $this->createMock(ToolProviderInterface::class);
        $provider->method('getTools')->willReturn([]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CollectToolsEvent::class))
            ->willReturnCallback(fn($event) => $event);

        $registry = new ToolRegistry([$provider], $dispatcher);
        $registry->getTools();
    }

    #[Test]
    public function getToolsReturnsEmptyArrayWhenNoProviders(): void
    {
        $registry = new ToolRegistry([], $this->dispatcher);
        $tools = $registry->getTools();

        self::assertCount(0, $tools);
    }

    #[Test]
    public function getToolsAppliesPageOverrides(): void
    {
        $tool1 = new ToolDefinition(name: 'search', description: 'Search', inputSchema: []);
        $tool2 = new ToolDefinition(name: 'navigation', description: 'Navigate', inputSchema: []);

        $provider = $this->createMock(ToolProviderInterface::class);
        $provider->method('getTools')->willReturn([$tool1, $tool2]);

        $pageConfig = new PageToolConfiguration(['search' => false]);

        $registry = new ToolRegistry([$provider], $this->dispatcher);
        $tools = $registry->getTools([], $pageConfig);

        self::assertCount(1, $tools);
        self::assertSame('navigation', $tools[0]->name);
    }

    #[Test]
    public function getToolsPageOverrideNullDoesNotFilter(): void
    {
        $tool = new ToolDefinition(name: 'search', description: 'Search', inputSchema: []);

        $provider = $this->createMock(ToolProviderInterface::class);
        $provider->method('getTools')->willReturn([$tool]);

        $pageConfig = new PageToolConfiguration(['navigation' => false]);

        $registry = new ToolRegistry([$provider], $this->dispatcher);
        $tools = $registry->getTools([], $pageConfig);

        self::assertCount(1, $tools);
    }

    /**
     * The toggle used to be looked up by tool name only, which works for a
     * provider whose single tool is named after it and silently does nothing
     * for one that derives a tool per form or per context. `tools.form.enabled:
     * false` therefore disabled nothing, and the form tools were advertised on
     * every site that had a form.
     */
    #[Test]
    public function aProviderIsDisabledByItsOwnKeyEvenWhenItsToolsAreNamedDifferently(): void
    {
        $provider = $this->createMock(ToolProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('form');
        $provider->method('getTools')->willReturn([
            new ToolDefinition(name: 'form-contact', description: 'Submit the form Contact', inputSchema: []),
            new ToolDefinition(name: 'form-newsletter', description: 'Submit the form Newsletter', inputSchema: []),
        ]);

        $registry = new ToolRegistry([$provider], $this->dispatcher);
        $tools = $registry->getTools(['tools' => ['form' => ['enabled' => false]]]);

        self::assertSame([], $tools);
    }

    #[Test]
    public function theToolsOwnKeyStillWinsOverTheProviderKey(): void
    {
        $provider = $this->createMock(ToolProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('form');
        $provider->method('getTools')->willReturn([
            new ToolDefinition(name: 'form-contact', description: 'Submit', inputSchema: []),
        ]);

        $registry = new ToolRegistry([$provider], $this->dispatcher);
        $tools = $registry->getTools([
            'tools' => [
                'form' => ['enabled' => false],
                'form-contact' => ['enabled' => true],
            ],
        ]);

        self::assertCount(1, $tools, 'a per-tool setting must still be able to re-enable one tool');
    }

    #[Test]
    public function providersReceiveTheSiteSettings(): void
    {
        // Without them a provider cannot tell which forms the site allows, and
        // would advertise every one it can find.
        $seen = null;
        $provider = $this->createMock(ToolProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('form');
        $provider->method('getTools')->willReturnCallback(function (array $settings) use (&$seen): array {
            $seen = $settings;

            return [];
        });

        $settings = ['tools' => ['form' => ['allowedForms' => 'contact']]];
        new ToolRegistry([$provider], $this->dispatcher)->getTools($settings);

        self::assertSame($settings, $seen);
    }
}
