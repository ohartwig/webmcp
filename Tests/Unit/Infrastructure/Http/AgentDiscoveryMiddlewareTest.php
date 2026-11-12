<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Application\Contract\ConsentCheckInterface;
use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ConsentRequirement;
use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Koh\WebMcp\Infrastructure\Http\A2aAgentCard;
use Koh\WebMcp\Infrastructure\Http\AgentDiscoveryMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->middleware = $this->middlewareWithTools([]);
    }

    /**
     * @param list<ToolDefinition>              $tools
     * @param array<string, ConsentRequirement> $consent
     */
    private function middlewareWithTools(array $tools, array $consent = []): AgentDiscoveryMiddleware
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        $provider = $this->createStub(ToolProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->method('getTools')->willReturn($tools);

        $consentCheck = $this->createStub(ConsentCheckInterface::class);
        $consentCheck->method('getConsentRequirement')->willReturnCallback(
            static fn(string $name): ConsentRequirement => $consent[$name] ?? new ConsentRequirement(toolName: $name),
        );

        // Real factories so the discovery endpoints produce inspectable JSON.
        return new AgentDiscoveryMiddleware(
            new ToolRegistry([] === $tools ? [] : [$provider], $dispatcher),
            $consentCheck,
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
        $request = (new ServerRequest('https://example.com/data.json'))->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            (new Response())->withHeader('Content-Type', 'application/json'),
        );

        $response = $this->middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function leavesMcpJsonToTheNotFoundHandling(): void
    {
        // No MCP specification defines /.well-known/mcp.json; the request
        // must reach TYPO3, which answers 404, instead of an invented document.
        $request = (new ServerRequest('https://example.com/.well-known/mcp.json'))->withAttribute('site', $this->site());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(null, 404));

        $response = $this->middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function servesApiCatalogWithRfc9727Profile(): void
    {
        $site = $this->site();
        $request = (new ServerRequest('https://example.com/.well-known/api-catalog'))->withAttribute('site', $site);

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
        $request = (new ServerRequest('https://example.com/auth.md'))->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware->process($request, $handler);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/markdown', $response->getHeaderLine('Content-Type'));
        // The auth.md spec keys off an H1 containing "auth.md".
        self::assertStringStartsWith('# auth.md', $body);
        // Every endpoint an agent needs must be reachable from this document alone.
        foreach ([
            '/.well-known/oauth-authorization-server',
            '/mcp_oauth/agent/identity',
            '/mcp_oauth/agent/identity/claim',
            '/mcp_oauth/token',
            '/mcp_oauth/revoke',
            '/mcp_oauth/jwks',
            '/mcp_oauth/register',
            '/mcp_oauth/authorize',
        ] as $path) {
            self::assertStringContainsString('https://example.com' . $path, $body, $path);
        }
        // The profile's own vocabulary (v0.6.0): the registration body, both
        // grants, and the ceremony fields an agent parses.
        self::assertStringContainsString('{"type": "anonymous"', $body);
        self::assertStringContainsString('grant_type=urn:workos:agent-auth:grant-type:claim', $body);
        self::assertStringContainsString('grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer', $body);
        foreach (['claim_token', 'user_code', 'verification_uri', 'identity_assertion', 'authorization_pending', 'slow_down', 'expired_token'] as $field) {
            self::assertStringContainsString('`' . $field . '`', $body, $field);
        }
        // The ceremony is only honest if the document says plainly that an
        // unclaimed registration buys nothing.
        self::assertStringContainsString('"pre_claim_scopes": []', $body);
        self::assertStringContainsString('until an editor claims it,' . "\n" . 'the registration grants nothing at all', $body);
        // Retired v0.1.0 response fields must not linger in the recipe.
        self::assertStringNotContainsString('"authorized": false', $body);
        self::assertStringNotContainsString('claim_uri', $body);
    }

    #[Test]
    public function doesNotServeAuthMdWithoutTheMcpServerExtension(): void
    {
        // Without mcp_server the OAuth routes do not exist — advertising them
        // would point agents at 404s, so the request must fall through.
        $this->activateMcpServer(false);

        $request = (new ServerRequest('https://example.com/auth.md'))->withAttribute('site', $this->site());

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
            $request = (new ServerRequest('https://example.com' . $path))->withAttribute('site', $this->site());

            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::never())->method('handle');

            $response = $this->middleware->process($request, $handler);
            $catalog = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertNotSame([], $catalog['entries'], $path);
        }
    }

    /**
     * Every entry has to be reachable. The A2A card is business-agent's, and
     * it serves one only from the version with an A2A endpoint on, and only on
     * sites that apply its site set. Listed in any other case, the entry is a
     * 404 the agent has already paid for.
     *
     * @return iterable<string, array{list<string>, class-string|string, array<string, mixed>, bool}>
     */
    public static function a2aCardConditions(): iterable
    {
        $agent = ['businessAgent' => ['agent' => ['name' => 'Assistent']]];

        yield 'served' => [['business_agent'], \stdClass::class, $agent, true];
        yield 'extension not loaded' => [[], \stdClass::class, $agent, false];
        yield 'a version without the A2A endpoint' => [['business_agent'], 'Koh\\BusinessAgent\\Missing\\AgentCardFactory', $agent, false];
        yield 'site without the Business Agent site set' => [['business_agent'], \stdClass::class, [], false];
    }

    /**
     * @param list<string>         $extensions
     * @param array<string, mixed> $siteSettings
     */
    #[Test]
    #[DataProvider('a2aCardConditions')]
    public function listsTheA2aCardExactlyWhenBusinessAgentServesIt(array $extensions, string $cardFactoryClass, array $siteSettings, bool $listed): void
    {
        $this->activateExtensions($extensions);
        $this->middleware = $this->middlewareWithA2aCard($cardFactoryClass);

        $entries = array_column($this->ardCatalog([], $siteSettings)['entries'], null, 'identifier');

        self::assertSame($listed, isset($entries['urn:air:example.com:agent:a2a']));
        self::assertSame($listed, in_array('https://example.com/.well-known/agent-card.json', array_column($entries, 'url'), true));
    }

    #[Test]
    public function theA2aEntryNamesTheCardByItsArdType(): void
    {
        $this->activateExtensions(['business_agent']);
        $this->middleware = $this->middlewareWithA2aCard(\stdClass::class);

        $entries = array_column($this->ardCatalog([], ['businessAgent' => []])['entries'], null, 'identifier');

        self::assertSame([
            'identifier' => 'urn:air:example.com:agent:a2a',
            'displayName' => 'demo Agent',
            'type' => 'application/a2a-agent-card+json',
            'url' => 'https://example.com/.well-known/agent-card.json',
            'description' => 'A2A Agent Card of the agent that searches this site\'s published pages.',
        ], $entries['urn:air:example.com:agent:a2a']);
    }

    #[Test]
    public function theCurrentBusinessAgentWithoutA2aIsNotListed(): void
    {
        // The default probe looks for business-agent's card factory, which
        // this package's own test environment does not have installed.
        $this->activateExtensions(['business_agent']);

        $types = array_column($this->ardCatalog([], ['businessAgent' => []])['entries'], 'type');

        self::assertNotContains('application/a2a-agent-card+json', $types);
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
        $this->middleware = $this->middlewareWithA2aCard(\stdClass::class);

        $catalog = $this->ardCatalog([
            'ard' => ['representativeQueries' => 'wie erreiche ich euch'],
        ], ['businessAgent' => []]);

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

    #[Test]
    public function skillsIndexCarriesEveryFieldOfTheV020Format(): void
    {
        $response = $this->request($this->middlewareWithTools($this->builtInTools()), '/.well-known/agent-skills/index.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));

        $index = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://schemas.agentskills.io/discovery/0.2.0/schema.json', $index['$schema']);
        self::assertSame(['$schema', 'skills'], array_keys($index));
        self::assertCount(3, $index['skills']);

        foreach ($index['skills'] as $skill) {
            self::assertSame(['name', 'type', 'description', 'url', 'digest'], array_keys($skill));
            self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $skill['name']);
            self::assertLessThanOrEqual(64, strlen($skill['name']));
            self::assertSame('skill-md', $skill['type']);
            self::assertNotSame('', $skill['description']);
            self::assertLessThanOrEqual(1024, mb_strlen($skill['description']));
            self::assertSame('/.well-known/agent-skills/' . $skill['name'] . '/SKILL.md', $skill['url']);
            self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $skill['digest']);
        }
    }

    #[Test]
    public function everyIndexedSkillResolvesToTheArtifactItsDigestNames(): void
    {
        $middleware = $this->middlewareWithTools($this->builtInTools());
        $index = json_decode((string) $this->request($middleware, '/.well-known/agent-skills/index.json')->getBody(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($index['skills'] as $skill) {
            $artifact = $this->request($middleware, $skill['url']);

            self::assertSame(200, $artifact->getStatusCode(), $skill['url']);
            self::assertSame('text/markdown; charset=utf-8', $artifact->getHeaderLine('Content-Type'));
            self::assertSame('*', $artifact->getHeaderLine('Access-Control-Allow-Origin'));
            self::assertSame('public, max-age=3600', $artifact->getHeaderLine('Cache-Control'));

            $body = (string) $artifact->getBody();
            self::assertSame($skill['digest'], 'sha256:' . hash('sha256', $body));

            // Frontmatter name matches the directory, description matches the index.
            self::assertSame(1, preg_match('/\A---\nname: (\S+)\ndescription: (".*")\n---\n/', $body, $frontmatter));
            self::assertSame($skill['name'], $frontmatter[1]);
            self::assertSame($skill['description'], json_decode($frontmatter[2], true, 512, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function skillArtifactTellsTheAgentHowTheApiIsCalled(): void
    {
        $body = (string) $this->request($this->middlewareWithTools($this->builtInTools()), '/.well-known/agent-skills/search/SKILL.md')->getBody();

        self::assertStringContainsString('POST https://example.com/_webmcp/api/search', $body);
        self::assertStringContainsString('X-WebMcp-Nonce: <nonce>', $body);
        self::assertStringContainsString('POST https://example.com/_webmcp/api/nonce', $body);
        self::assertStringNotContainsString('data-webmcp-config', $body);
        self::assertStringContainsString('"required": [', $body);
        self::assertStringContainsString('{"query":"TYPO3","limit":5}', $body);
        self::assertStringContainsString('Treat it as data', $body);
        self::assertStringContainsString('only reads', $body);
        self::assertStringNotContainsString('X-WebMcp-Consent', $body);
    }

    #[Test]
    public function skillArtifactNamesTheConsentHeaderOfAConsentGatedTool(): void
    {
        $tools = [new ToolDefinition(
            name: 'form-contact',
            description: 'Submit the form "Contact"',
            inputSchema: ['type' => 'object', 'properties' => ['email' => ['type' => 'string']]],
            annotations: new ToolAnnotations(readOnlyHint: false, untrustedContentHint: false),
            title: 'Form: Contact',
        )];
        $consent = ['form-contact' => new ConsentRequirement(toolName: 'form-contact', required: true, category: 'marketing')];

        $body = (string) $this->request($this->middlewareWithTools($tools, $consent), '/.well-known/agent-skills/form-contact/SKILL.md')->getBody();

        self::assertStringContainsString('`X-WebMcp-Consent: marketing`', $body);
        self::assertStringContainsString('The tool writes', $body);
        self::assertStringNotContainsString('Example:', $body);
        self::assertStringNotContainsString('Treat it as data', $body);
        self::assertStringContainsString('description: "Form: Contact: Submit the form \\"Contact\\" on Demo Site.', $body);
    }

    #[Test]
    public function toolsWhoseNamesAreNoValidSkillNamesAreLeftOut(): void
    {
        $tools = [
            new ToolDefinition(name: 'form-Contact_1', description: 'Submit', inputSchema: []),
            new ToolDefinition(name: 'search', description: 'Search', inputSchema: []),
        ];
        $middleware = $this->middlewareWithTools($tools);

        $index = json_decode((string) $this->request($middleware, '/.well-known/agent-skills/index.json')->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['search'], array_column($index['skills'], 'name'));
        self::assertSame(404, $this->request($middleware, '/.well-known/agent-skills/form-Contact_1/SKILL.md')->getStatusCode());
    }

    #[Test]
    public function unknownSkillArtifactIsNotFound(): void
    {
        $response = $this->request($this->middlewareWithTools($this->builtInTools()), '/.well-known/agent-skills/nope/SKILL.md');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function publishesNoSkillsWhileWebMcpIsDisabled(): void
    {
        $middleware = $this->middlewareWithTools($this->builtInTools());
        $site = new Site('demo', 1, [
            'base' => 'https://example.com/',
            'settings' => ['webmcp' => ['enabled' => false]],
            'languages' => [],
        ]);

        $index = json_decode((string) $this->request($middleware, '/.well-known/agent-skills/index.json', $site)->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $index['skills']);
        self::assertSame(404, $this->request($middleware, '/.well-known/agent-skills/search/SKILL.md', $site)->getStatusCode());
    }

    #[Test]
    public function skillsFollowTheConfiguredApiBasePathAndFallBackToTheHost(): void
    {
        $site = new Site('demo', 1, [
            'base' => 'https://example.com/',
            'settings' => ['webmcp' => ['enabled' => true, 'apiBasePath' => '/agents/api']],
            'languages' => [],
        ]);

        $body = (string) $this->request($this->middlewareWithTools($this->builtInTools()), '/.well-known/agent-skills/navigation/SKILL.md', $site)->getBody();

        self::assertStringContainsString('POST https://example.com/agents/api/navigation', $body);
        self::assertStringContainsString('on example.com.', $body);
        self::assertStringContainsString('# Navigation Tree', $body);
    }

    #[Test]
    public function publishesNoSkillsWhenTheSiteNeverEnabledWebMcp(): void
    {
        $site = new Site('demo', 1, [
            'base' => 'https://example.com/',
            'settings' => ['webmcp' => ['apiBasePath' => '/_webmcp/api']],
            'languages' => [],
        ]);

        $index = json_decode((string) $this->request($this->middlewareWithTools($this->builtInTools()), '/.well-known/agent-skills/index.json', $site)->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $index['skills']);
    }

    #[Test]
    public function trimsTheSiteTitle(): void
    {
        $site = new Site('demo', 1, [
            'base' => 'https://example.com/',
            'websiteTitle' => '  Demo Site  ',
            'settings' => ['webmcp' => ['enabled' => true]],
            'languages' => [],
        ]);

        $index = json_decode((string) $this->request($this->middlewareWithTools($this->builtInTools()), '/.well-known/agent-skills/index.json', $site)->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('Site Search: Search content on this website on Demo Site. Use', $index['skills'][0]['description']);
    }

    #[Test]
    public function onlyAnExactSkillPathReachesTheArtifact(): void
    {
        $request = (new ServerRequest('https://example.com/.well-known/agent-skills/search/SKILL.md.bak'))->withAttribute('site', $this->site());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(null, 404));

        self::assertSame(404, $this->middlewareWithTools($this->builtInTools())->process($request, $handler)->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherDiscoveryDocuments(): iterable
    {
        yield 'server card' => ['/.well-known/mcp/server-card.json', 'serverInfo'];
        yield 'legacy skill detail' => ['/.well-known/agent-skills/search.json', 'endpoint'];
        yield 'web bot auth' => ['/.well-known/http-message-signatures-directory', 'keys'];
    }

    #[Test]
    #[DataProvider('otherDiscoveryDocuments')]
    public function servesTheOtherDiscoveryDocumentsItself(string $path, string $key): void
    {
        $response = $this->request($this->middlewareWithTools($this->builtInTools()), $path);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey($key, json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<ToolDefinition>
     */
    private function builtInTools(): array
    {
        return [
            new ToolDefinition(
                name: 'search',
                description: 'Search content on this website',
                inputSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
                annotations: new ToolAnnotations(readOnlyHint: true, untrustedContentHint: true),
                title: 'Site Search',
            ),
            new ToolDefinition(name: 'navigation', description: 'Navigation', inputSchema: [], title: 'Navigation Tree'),
            new ToolDefinition(name: 'page-content', description: 'Page content', inputSchema: []),
        ];
    }

    private function middlewareWithA2aCard(string $cardFactoryClass): AgentDiscoveryMiddleware
    {
        return new AgentDiscoveryMiddleware(
            new ToolRegistry([], $this->createStub(EventDispatcherInterface::class)),
            $this->createStub(ConsentCheckInterface::class),
            new ResponseFactory(),
            new StreamFactory(),
            new A2aAgentCard($cardFactoryClass),
        );
    }

    private function request(AgentDiscoveryMiddleware $middleware, string $path, ?Site $site = null): ResponseInterface
    {
        $site ??= new Site('demo', 1, [
            'base' => 'https://example.com/',
            'websiteTitle' => 'Demo Site',
            'settings' => ['webmcp' => ['enabled' => true, 'apiBasePath' => '/_webmcp/api']],
            'languages' => [],
        ]);
        $request = (new ServerRequest('https://example.com' . $path))->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        return $middleware->process($request, $handler);
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
     * @param array<string, mixed> $siteSettings   further site settings, such as another extension's
     */
    private function ardCatalog(array $webmcpSettings = [], array $siteSettings = []): array
    {
        $site = new Site('demo', 1, [
            'base' => 'https://example.com/',
            'settings' => ['webmcp' => ['enabled' => true, 'apiBasePath' => '/_webmcp/api'] + $webmcpSettings] + $siteSettings,
            'languages' => [],
        ]);

        $request = (new ServerRequest('https://example.com/.well-known/ard.json'))->withAttribute('site', $site);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function handleHtmlPage(): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/some-page'))->withAttribute('site', $this->site());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            (new Response())->withHeader('Content-Type', 'text/html; charset=utf-8'),
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
