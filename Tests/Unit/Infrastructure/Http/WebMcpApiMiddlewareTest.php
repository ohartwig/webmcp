<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Application\Contract\ErrorLoggerInterface;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;
use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Application\Service\ConsentService;
use Koh\WebMcp\Application\Service\ToolExecutionService;
use Koh\WebMcp\Application\Service\ToolInvocationLogger;
use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Koh\WebMcp\Domain\Model\ToolResult;
use Koh\WebMcp\Infrastructure\Http\WebMcpApiMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class WebMcpApiMiddlewareTest extends TestCase
{
    private WebMcpApiMiddleware $middleware;
    private SiteConfigurationProviderInterface&MockObject $siteConfigurationProvider;
    private FrontendInterface&MockObject $cache;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private ToolHandlerInterface&MockObject $searchHandler;
    private ToolHandlerInterface&MockObject $rogueHandler;
    private ToolHandlerInterface&MockObject $shopHandler;

    protected function setUp(): void
    {
        $this->searchHandler = $this->createMock(ToolHandlerInterface::class);
        $this->searchHandler->method('getToolName')->willReturn('search');
        $this->searchHandler->method('execute')->willReturn(ToolResult::success(['results' => []]));

        // A handler nobody advertises: registered through the DI tag, but no
        // provider emits a tool of that name.
        $this->rogueHandler = $this->createMock(ToolHandlerInterface::class);
        $this->rogueHandler->method('getToolName')->willReturn('rogue');
        $this->rogueHandler->method('execute')->willReturn(ToolResult::success(['leaked' => true]));

        // A third-party provider: its tool is named after nothing in the
        // site's settings, so it has no entry of its own.
        $this->shopHandler = $this->createMock(ToolHandlerInterface::class);
        $this->shopHandler->method('getToolName')->willReturn('product-search');
        $this->shopHandler->method('execute')->willReturn(ToolResult::success(['products' => []]));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        $toolExecutionService = new ToolExecutionService(
            [$this->searchHandler, $this->rogueHandler, $this->shopHandler],
            $this->createMock(ErrorLoggerInterface::class),
            $dispatcher,
        );

        $searchProvider = $this->createMock(ToolProviderInterface::class);
        $searchProvider->method('getIdentifier')->willReturn('search');
        $searchProvider->method('getTools')->willReturn([
            new ToolDefinition(name: 'search', description: 'Search', inputSchema: []),
        ]);
        $shopProvider = $this->createMock(ToolProviderInterface::class);
        $shopProvider->method('getIdentifier')->willReturn('shop');
        $shopProvider->method('getTools')->willReturn([
            new ToolDefinition(name: 'product-search', description: 'Find products', inputSchema: []),
        ]);
        $toolRegistry = new ToolRegistry([$searchProvider, $shopProvider], $dispatcher);

        $this->siteConfigurationProvider = $this->createMock(SiteConfigurationProviderInterface::class);
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);

        $logRepository = $this->createMock(ToolInvocationLogRepositoryInterface::class);
        $invocationLogger = new ToolInvocationLogger($logRepository, $this->createMock(ErrorLoggerInterface::class));

        $this->middleware = new WebMcpApiMiddleware(
            $toolExecutionService,
            $this->siteConfigurationProvider,
            $invocationLogger,
            $this->cache,
            $this->responseFactory,
            $this->streamFactory,
            $toolRegistry,
            new ConsentService(),
        );
    }

    public function testRateLimitFollowsTheCallerNotTheNonce(): void
    {
        // The point of the change: a nonce is minted on every page render, so
        // keying the budget on it meant a caller who ran out simply reloaded
        // the page and got another 60. The key must survive that.
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->setupJsonResponseMock();

        $seenKeys = [];
        $this->cache->method('get')->willReturnCallback(
            function (string $key) use (&$seenKeys) {
                if (str_starts_with($key, 'ratelimit_')) {
                    $seenKeys[] = $key;

                    return false;
                }

                return str_starts_with($key, 'nonce_')
                    ? $this->buildCachedNonceData(str_replace('nonce_', '', $key))
                    : false;
            }
        );

        $handler = $this->createMock(RequestHandlerInterface::class);

        foreach ([str_repeat('a', 64), str_repeat('b', 64)] as $nonce) {
            $this->middleware->process(
                $this->createRequest('/_webmcp/api/search', 'POST', $nonce, '{}'),
                $handler,
            );
        }

        self::assertCount(2, $seenKeys);
        self::assertSame(
            $seenKeys[0],
            $seenKeys[1],
            'Two different nonces from the same caller must share one budget',
        );
    }

    private function createRequest(
        string $path,
        string $method = 'POST',
        string $nonce = '',
        string $body = '',
        string $remoteAddr = '127.0.0.1',
        string $userAgent = 'TestAgent/1.0',
        string $consent = '',
    ): ServerRequestInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);
        $request->method('getHeaderLine')->willReturnCallback(
            fn(string $name) => match ($name) {
                'X-WebMcp-Nonce' => $nonce,
                'X-WebMcp-Consent' => $consent,
                'User-Agent' => $userAgent,
                default => '',
            }
        );
        $request->method('getBody')->willReturn($stream);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }

    private function buildFingerprint(string $ip = '127.0.0.1', string $userAgent = 'TestAgent/1.0'): string
    {
        return hash('sha256', $ip . '|' . $userAgent);
    }

    /**
     * @param array<string, bool> $toolOverrides
     */
    private function buildCachedNonceData(
        string $nonce,
        string $ip = '127.0.0.1',
        string $userAgent = 'TestAgent/1.0',
        array $toolOverrides = [],
    ): string {
        return json_encode([
            'nonce' => $nonce,
            'fingerprint' => $this->buildFingerprint($ip, $userAgent),
            'created' => time(),
            'pageId' => 1,
            'toolOverrides' => $toolOverrides,
        ]);
    }

    private function setupJsonResponseMock(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();
        $this->responseFactory->method('createResponse')->willReturn($response);
    }

    /**
     * Serves a valid nonce for every nonce_ key, optionally with page overrides.
     *
     * @param array<string, bool> $toolOverrides
     */
    private function cacheServesValidNonce(array $toolOverrides = []): void
    {
        $this->cache->method('get')->willReturnCallback(
            fn(string $key) => str_starts_with($key, 'nonce_')
                ? $this->buildCachedNonceData(str_replace('nonce_', '', $key), toolOverrides: $toolOverrides)
                : false
        );
    }

    #[Test]
    public function nonApiRequestsPassThrough(): void
    {
        $request = $this->createRequest('/some/other/path');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $expectedResponse = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function rejectsGetRequests(): void
    {
        $this->setupJsonResponseMock();
        $request = $this->createRequest('/_webmcp/api/search', 'GET');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(405);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rejectsDisabledWebmcp(): void
    {
        $this->setupJsonResponseMock();
        $request = $this->createRequest('/_webmcp/api/search');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => false, 'apiBasePath' => '/_webmcp/api']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(403);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rejectsMissingNonce(): void
    {
        $this->setupJsonResponseMock();
        $request = $this->createRequest('/_webmcp/api/search', 'POST', '');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(403);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rejectsInvalidNonceFormat(): void
    {
        $this->setupJsonResponseMock();
        $request = $this->createRequest('/_webmcp/api/search', 'POST', 'invalid-nonce!');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(403);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rejectsNonceNotInCache(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/search', 'POST', $validHexNonce);
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->cache->method('get')->willReturn(false);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(403);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rejectsNonceWithWrongFingerprint(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/search', 'POST', $validHexNonce);
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $wrongFingerprint = $this->buildCachedNonceData($validHexNonce, '10.0.0.1', 'DifferentAgent');
        $this->cache->method('get')->willReturn($wrongFingerprint);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(403);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rejectsInvalidToolNameWithSlash(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/../../etc/passwd', 'POST', $validHexNonce);
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $cachedData = $this->buildCachedNonceData($validHexNonce);
        $this->cache->method('get')->willReturn($cachedData);

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(400);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function executesToolSuccessfully(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/search', 'POST', $validHexNonce, '{"query":"test"}');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->cacheServesValidNonce();

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(200);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function returnsNotFoundForUnknownTool(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/unknown-tool', 'POST', $validHexNonce, '{}');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->cacheServesValidNonce();

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(404);

        $this->middleware->process($request, $handler);
    }

    /**
     * Switching a tool off in the site settings used to change what the page
     * advertised and nothing else: the API ran any tool with a handler. The
     * settings are now the gate at execution too — the tool is unknown, the
     * handler never runs.
     */
    #[Test]
    public function aToolDisabledInTheSiteSettingsIsUnknownToTheApi(): void
    {
        $this->setupJsonResponseMock();
        $this->siteConfigurationProvider->method('getSettings')->willReturn([
            'enabled' => true,
            'apiBasePath' => '/_webmcp/api',
            'tools' => ['search' => ['enabled' => false]],
        ]);
        $this->cacheServesValidNonce();
        $this->searchHandler->expects(self::never())->method('execute');

        $this->responseFactory->expects(self::once())->method('createResponse')->with(404);

        $this->middleware->process(
            $this->createRequest('/_webmcp/api/search', 'POST', str_repeat('a', 64), '{"query":"x"}'),
            $this->createMock(RequestHandlerInterface::class),
        );
    }

    /**
     * The page TSconfig override of the page the nonce was minted on travels
     * with the nonce, so a tool an editor switched off for that page cannot
     * be called from it either.
     */
    #[Test]
    public function aToolDisabledByThePageTheNonceWasMintedOnIsUnknown(): void
    {
        $this->setupJsonResponseMock();
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->cacheServesValidNonce(['search' => false]);
        $this->searchHandler->expects(self::never())->method('execute');

        $this->responseFactory->expects(self::once())->method('createResponse')->with(404);

        $this->middleware->process(
            $this->createRequest('/_webmcp/api/search', 'POST', str_repeat('a', 64), '{"query":"x"}'),
            $this->createMock(RequestHandlerInterface::class),
        );
    }

    /**
     * A handler alone is not a tool. Something has to advertise it through
     * a provider; otherwise it is reachable by whoever reads the source and
     * nobody who reads the site settings.
     */
    #[Test]
    public function aHandlerNoProviderAdvertisesIsUnknown(): void
    {
        $this->setupJsonResponseMock();
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->cacheServesValidNonce();
        $this->rogueHandler->expects(self::never())->method('execute');

        $this->responseFactory->expects(self::once())->method('createResponse')->with(404);

        $this->middleware->process(
            $this->createRequest('/_webmcp/api/rogue', 'POST', str_repeat('a', 64), '{}'),
            $this->createMock(RequestHandlerInterface::class),
        );
    }

    /**
     * The same gate applies to a provider another extension registers: its
     * provider key in the site settings switches its tools off at the API.
     */
    #[Test]
    public function aThirdPartyProviderIsGatedByItsOwnSettingsKey(): void
    {
        $this->setupJsonResponseMock();
        $this->siteConfigurationProvider->method('getSettings')->willReturn([
            'enabled' => true,
            'apiBasePath' => '/_webmcp/api',
            'tools' => ['shop' => ['enabled' => false]],
        ]);
        $this->cacheServesValidNonce();
        $this->shopHandler->expects(self::never())->method('execute');

        $this->responseFactory->expects(self::once())->method('createResponse')->with(404);

        $this->middleware->process(
            $this->createRequest('/_webmcp/api/product-search', 'POST', str_repeat('a', 64), '{}'),
            $this->createMock(RequestHandlerInterface::class),
        );
    }

    /**
     * Consent used to be a client-side matter only ("Client-side JS handles
     * actual consent gating"). A consent-gated tool now refuses a call that
     * does not state the granted category.
     */
    #[Test]
    public function aConsentGatedToolRefusesACallWithoutTheGrantedCategory(): void
    {
        $this->setupJsonResponseMock();
        $this->siteConfigurationProvider->method('getSettings')->willReturn([
            'enabled' => true,
            'apiBasePath' => '/_webmcp/api',
            'tools' => ['search' => ['enabled' => true, 'consent' => ['required' => true, 'category' => 'functional']]],
        ]);
        $this->cacheServesValidNonce();
        $this->searchHandler->expects(self::never())->method('execute');

        $this->responseFactory->expects(self::once())->method('createResponse')->with(403);

        $this->middleware->process(
            $this->createRequest('/_webmcp/api/search', 'POST', str_repeat('a', 64), '{"query":"x"}'),
            $this->createMock(RequestHandlerInterface::class),
        );
    }

    #[Test]
    public function aConsentGatedToolRunsWhenTheGrantedCategoryIsStated(): void
    {
        $this->setupJsonResponseMock();
        $this->siteConfigurationProvider->method('getSettings')->willReturn([
            'enabled' => true,
            'apiBasePath' => '/_webmcp/api',
            'tools' => ['search' => ['enabled' => true, 'consent' => ['required' => true, 'category' => 'functional']]],
        ]);
        $this->cacheServesValidNonce();
        $this->searchHandler->expects(self::once())->method('execute')->willReturn(ToolResult::success([]));

        $this->responseFactory->expects(self::once())->method('createResponse')->with(200);

        $this->middleware->process(
            $this->createRequest('/_webmcp/api/search', 'POST', str_repeat('a', 64), '{"query":"x"}', consent: 'functional'),
            $this->createMock(RequestHandlerInterface::class),
        );
    }

    #[Test]
    public function aConsentGatedToolRefusesAWrongCategory(): void
    {
        $this->setupJsonResponseMock();
        $this->siteConfigurationProvider->method('getSettings')->willReturn([
            'enabled' => true,
            'apiBasePath' => '/_webmcp/api',
            'tools' => ['search' => ['enabled' => true, 'consent' => ['required' => true, 'category' => 'analytics']]],
        ]);
        $this->cacheServesValidNonce();
        $this->searchHandler->expects(self::never())->method('execute');

        $this->responseFactory->expects(self::once())->method('createResponse')->with(403);

        $this->middleware->process(
            $this->createRequest('/_webmcp/api/search', 'POST', str_repeat('a', 64), '{"query":"x"}', consent: 'functional'),
            $this->createMock(RequestHandlerInterface::class),
        );
    }

    #[Test]
    public function rejectsInvalidJsonBody(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/search', 'POST', $validHexNonce, '{invalid json}');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->cacheServesValidNonce();

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(400);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rateLimitRejectsExcessiveRequests(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/search', 'POST', $validHexNonce, '{}');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $cachedData = $this->buildCachedNonceData($validHexNonce);
        $this->cache->method('get')->willReturnCallback(
            fn(string $key) => match (true) {
                str_starts_with($key, 'nonce_') => $cachedData,
                str_starts_with($key, 'ratelimit_') => '60',
                default => false,
            }
        );

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(429);

        $this->middleware->process($request, $handler);
    }
}
