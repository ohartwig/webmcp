<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Infrastructure\Http\AgentDiscoveryMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class AgentDiscoveryMiddlewareTest extends TestCase
{
    private AgentDiscoveryMiddleware $middleware;

    protected function setUp(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        // Real factories so the discovery endpoints produce inspectable JSON.
        $this->middleware = new AgentDiscoveryMiddleware(
            new ToolRegistry([], $dispatcher),
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    #[Test]
    public function passesThroughWhenNoSiteAttribute(): void
    {
        $request = new ServerRequest('https://example.com/some-page');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response());

        $response = $this->middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function addsAgentDiscoveryLinkHeadersToHtmlResponses(): void
    {
        $response = $this->handleHtmlPage();

        $links = $response->getHeader('Link');
        self::assertContains('<https://example.com/.well-known/api-catalog>; rel="api-catalog"', $links);
        self::assertContains('<https://example.com/.well-known/mcp/server-card.json>; rel="mcp-server-card"', $links);
        self::assertContains('<https://example.com/llms.txt>; rel="describedby"; type="text/markdown"', $links);
    }

    #[Test]
    public function leavesNonHtmlResponsesUntouched(): void
    {
        $site = $this->site();
        $request = new ServerRequest('https://example.com/data.json')->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            new Response()->withHeader('Content-Type', 'application/json'),
        );

        $response = $this->middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function servesMcpDiscoveryWithoutCallingTheHandler(): void
    {
        $site = $this->site();
        $request = new ServerRequest('https://example.com/.well-known/mcp.json')->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('demo', $payload['mcp']['name']);
        self::assertSame('https://example.com/_webmcp/api', $payload['mcp']['transport']['endpoint']);
    }

    #[Test]
    public function servesApiCatalogWithRfc9727Profile(): void
    {
        $site = $this->site();
        $request = new ServerRequest('https://example.com/.well-known/api-catalog')->withAttribute('site', $site);

        $response = $this->middleware->process($request, $this->createMock(RequestHandlerInterface::class));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/linkset+json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('rfc9727', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function servesAuthMdAsMarkdownWithTheRequiredHeading(): void
    {
        $this->activateMcpServer(true);

        $site = $this->site();
        $request = new ServerRequest('https://example.com/auth.md')->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware->process($request, $handler);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/markdown', $response->getHeaderLine('Content-Type'));
        // The auth.md spec keys off an H1 containing "auth.md".
        self::assertStringStartsWith('# auth.md', $body);
        // Every endpoint an agent needs must be reachable from this document alone.
        self::assertStringContainsString('https://example.com/mcp_oauth/register', $body);
        self::assertStringContainsString('https://example.com/mcp_oauth/agent/identity', $body);
        // The anonymous ceremony is only honest if the document says plainly
        // that the credential it hands out does not work yet.
        self::assertStringContainsString('"authorized": false', $body);
        self::assertStringContainsString('https://example.com/mcp_oauth/authorize', $body);
        self::assertStringContainsString('https://example.com/mcp_oauth/token', $body);
        self::assertStringContainsString('https://example.com/mcp_oauth/revoke', $body);
    }

    #[Test]
    public function doesNotServeAuthMdWithoutTheMcpServerExtension(): void
    {
        // Without mcp_server the OAuth routes do not exist — advertising them
        // would point agents at 404s, so the request must fall through.
        $this->activateMcpServer(false);

        $request = new ServerRequest('https://example.com/auth.md')->withAttribute('site', $this->site());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response());

        $response = $this->middleware->process($request, $handler);

        self::assertStringNotContainsString('# auth.md', (string) $response->getBody());
    }

    #[Test]
    public function servesTheArdCatalogUnderBothNames(): void
    {
        $this->activateExtensions([]);

        foreach (['/.well-known/ard.json', '/.well-known/ai-catalog.json'] as $path) {
            $request = new ServerRequest('https://example.com' . $path)->withAttribute('site', $this->site());

            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::never())->method('handle');

            $response = $this->middleware->process($request, $handler);
            $catalog = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertNotSame([], $catalog['entries'], $path);
        }
    }

    /**
     * Every entry has to be reachable. A catalogue that names an endpoint this
     * installation does not serve costs the agent a request and returns a 404,
     * which is worse than a shorter catalogue.
     */
    #[Test]
    public function listsTheA2aCardOnlyWhileTheExtensionServingItIsLoaded(): void
    {
        $this->activateExtensions([]);
        $without = array_column($this->ardCatalog()['entries'], 'url');
        self::assertNotContains('https://example.com/.well-known/agent-card.json', $without);

        $this->activateExtensions(['business_agent']);
        $with = array_column($this->ardCatalog()['entries'], 'url');
        self::assertContains('https://example.com/.well-known/agent-card.json', $with);
    }

    /**
     * ARD §4.1: a manifest is an envelope with specVersion and a host object
     * around the entries; a registry validates the envelope before it reads a
     * single entry. The host identifier is the publisher domain the URNs are
     * anchored to.
     */
    #[Test]
    public function wrapsTheEntriesInTheArdEnvelope(): void
    {
        $this->activateExtensions([]);
        $catalog = $this->ardCatalog();

        self::assertSame('1.0', $catalog['specVersion']);
        self::assertSame('example.com', $catalog['host']['identifier']);
        self::assertNotSame('', $catalog['host']['displayName']);
        self::assertIsArray($catalog['entries']);
    }

    #[Test]
    public function everyEntryCarriesTheFieldsArdRequires(): void
    {
        $this->activateExtensions([]);

        foreach ($this->ardCatalog()['entries'] as $entry) {
            self::assertArrayHasKey('identifier', $entry);
            self::assertStringStartsWith('urn:air:example.com:', $entry['identifier']);
            self::assertNotSame('', $entry['displayName'] ?? '');
            self::assertNotSame('', $entry['type'] ?? '');
            self::assertNotSame('', $entry['url'] ?? '');
        }
    }

    #[Test]
    public function omitsRepresentativeQueriesUntilAnEditorWritesThem(): void
    {
        $this->activateExtensions([]);

        foreach ($this->ardCatalog()['entries'] as $entry) {
            self::assertArrayNotHasKey('representativeQueries', $entry);
        }
    }

    #[Test]
    public function publishesAtMostFiveConfiguredRepresentativeQueries(): void
    {
        $this->activateExtensions([]);

        $catalog = $this->ardCatalog([
            'ard' => ['representativeQueries' => "one\n two \n\nthree\nfour\nfive\nsix"],
        ]);
        $entries = array_column($catalog['entries'], 'representativeQueries', 'identifier');

        self::assertSame(['one', 'two', 'three', 'four', 'five'], $entries['urn:air:example.com:server:mcp']);
    }

    /**
     * They route a question to a resource, so they belong on the resources an
     * agent can invoke. A linkset and a Markdown summary answer no question.
     */
    #[Test]
    public function carriesRepresentativeQueriesOnlyOnTheInvocableResources(): void
    {
        $this->activateExtensions(['business_agent']);

        $catalog = $this->ardCatalog([
            'ard' => ['representativeQueries' => 'wie erreiche ich euch'],
        ]);

        $withQueries = [];
        foreach ($catalog['entries'] as $entry) {
            if (array_key_exists('representativeQueries', $entry)) {
                $withQueries[] = $entry['identifier'];
            }
        }

        self::assertSame(
            ['urn:air:example.com:server:mcp', 'urn:air:example.com:agent:a2a'],
            $withQueries,
        );
    }

    #[Test]
    public function advertisesTheCatalogueInTheLinkHeader(): void
    {
        $links = $this->handleHtmlPage()->getHeader('Link');

        self::assertContains('<https://example.com/.well-known/ard.json>; rel="ard"', $links);
        self::assertContains('<https://example.com/.well-known/ard.json>; rel="ai-catalog"', $links);
    }

    private function activateMcpServer(bool $active): void
    {
        $this->activateExtensions($active ? ['mcp_server'] : []);
    }

    /**
     * @param list<string> $keys
     */
    private function activateExtensions(array $keys): void
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('isPackageActive')
            ->willReturnCallback(static fn(string $key): bool => in_array($key, $keys, true));

        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    /**
     * @param array<string, mixed> $webmcpSettings
     */
    private function ardCatalog(array $webmcpSettings = []): array
    {
        $site = new Site('demo', 1, [
            'base' => 'https://example.com/',
            'settings' => ['webmcp' => ['enabled' => true, 'apiBasePath' => '/_webmcp/api'] + $webmcpSettings],
            'languages' => [],
        ]);

        $request = new ServerRequest('https://example.com/.well-known/ard.json')->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function handleHtmlPage(): ResponseInterface
    {
        $request = new ServerRequest('https://example.com/some-page')->withAttribute('site', $this->site());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            new Response()->withHeader('Content-Type', 'text/html; charset=utf-8'),
        );

        return $this->middleware->process($request, $handler);
    }

    private function site(): Site
    {
        return new Site('demo', 1, [
            'base' => 'https://example.com/',
            'settings' => ['webmcp' => ['enabled' => true, 'apiBasePath' => '/_webmcp/api']],
            'languages' => [],
        ]);
    }
}
