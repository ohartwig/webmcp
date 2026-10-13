<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Application\Service\ToolExecutionService;
use Koh\WebMcp\Application\Service\ToolInvocationLogger;
use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Koh\WebMcp\Domain\Model\ToolResult;
use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use Koh\WebMcp\Infrastructure\Http\WebMcpApiMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Koh\WebMcp\Application\Contract\ErrorLoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class WebMcpApiMiddlewareTest extends TestCase
{
    private WebMcpApiMiddleware $middleware;
    private SiteConfigurationProviderInterface $siteConfigurationProvider;
    private FrontendInterface $cache;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private ToolHandlerInterface $searchHandler;

    protected function setUp(): void
    {
        $this->searchHandler = $this->createMock(ToolHandlerInterface::class);
        $this->searchHandler->method('getToolName')->willReturn('search');
        $this->searchHandler->method('execute')->willReturn(ToolResult::success(['results' => []]));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        $toolExecutionService = new ToolExecutionService(
            [$this->searchHandler],
            $this->createMock(ErrorLoggerInterface::class),
            $dispatcher,
        );

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

    private function buildCachedNonceData(string $nonce, string $ip = '127.0.0.1', string $userAgent = 'TestAgent/1.0'): string
    {
        return json_encode([
            'nonce' => $nonce,
            'fingerprint' => $this->buildFingerprint($ip, $userAgent),
            'created' => time(),
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

        $cachedData = $this->buildCachedNonceData($validHexNonce);
        $this->cache->method('get')->willReturnCallback(
            fn(string $key) => str_starts_with($key, 'nonce_') ? $cachedData : false
        );

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

        $cachedData = $this->buildCachedNonceData($validHexNonce);
        $this->cache->method('get')->willReturnCallback(
            fn(string $key) => str_starts_with($key, 'nonce_') ? $cachedData : false
        );

        $handler = $this->createMock(RequestHandlerInterface::class);

        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(404);

        $this->middleware->process($request, $handler);
    }

    #[Test]
    public function rejectsInvalidJsonBody(): void
    {
        $this->setupJsonResponseMock();
        $validHexNonce = str_repeat('a', 64);
        $request = $this->createRequest('/_webmcp/api/search', 'POST', $validHexNonce, '{invalid json}');
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $cachedData = $this->buildCachedNonceData($validHexNonce);
        $this->cache->method('get')->willReturnCallback(
            fn(string $key) => str_starts_with($key, 'nonce_') ? $cachedData : false
        );

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
