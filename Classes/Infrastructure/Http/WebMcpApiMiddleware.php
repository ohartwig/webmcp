<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use Koh\WebMcp\Application\Service\ToolExecutionService;
use Koh\WebMcp\Application\Service\ToolInvocationLogger;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final readonly class WebMcpApiMiddleware implements MiddlewareInterface
{
    private const int RATE_LIMIT_MAX_REQUESTS = 60;
    private const int RATE_LIMIT_WINDOW_SECONDS = 60;
    private const int MAX_BODY_SIZE = 65536;

    public function __construct(
        private ToolExecutionService $toolExecutionService,
        private SiteConfigurationProviderInterface $siteConfigurationProvider,
        private ToolInvocationLogger $invocationLogger,
        private FrontendInterface $cache,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $site = $request->getAttribute('site');
        $settings = $this->siteConfigurationProvider->getSettings(
            $site instanceof Site ? $site->getIdentifier() : '',
        );
        $apiBasePath = $settings['apiBasePath'] ?? '/_webmcp/api';

        if (!str_starts_with($path, $apiBasePath . '/')) {
            return $handler->handle($request);
        }

        if (!((bool) ($settings['enabled'] ?? false))) {
            return $this->jsonResponse(['error' => 'WebMCP is disabled'], 403);
        }

        if ('POST' !== $request->getMethod()) {
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $toolName = substr($path, strlen($apiBasePath) + 1);
        $toolName = urldecode($toolName);

        if ('' === $toolName || str_contains($toolName, '/')) {
            return $this->jsonResponse(['error' => 'Invalid tool name'], 400);
        }

        // Validate CSRF nonce with client fingerprint
        $nonce = $request->getHeaderLine('X-WebMcp-Nonce');
        if ('' === $nonce || !$this->validateNonce($nonce, $request)) {
            return $this->jsonResponse(['error' => 'Invalid or missing nonce'], 403);
        }

        // Rate limiting per caller. Deliberately NOT per nonce: a nonce is
        // minted on every page render, so a caller who spent its budget simply
        // reloads and gets a fresh one. The fingerprint survives that.
        if (!$this->checkRateLimit($this->buildClientFingerprint($request))) {
            return $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
        }

        // Parse input with size limit
        $body = (string) $request->getBody();
        if (strlen($body) > self::MAX_BODY_SIZE) {
            return $this->jsonResponse(['error' => 'Request body too large'], 413);
        }
        $input = [];
        if ('' !== $body) {
            try {
                $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($input)) {
                    return $this->jsonResponse(['error' => 'Input must be a JSON object'], 400);
                }
            } catch (\JsonException) {
                return $this->jsonResponse(['error' => 'Invalid JSON body'], 400);
            }
        }

        if (!$this->toolExecutionService->hasHandler($toolName)) {
            return $this->jsonResponse(['error' => 'Unknown tool'], 404);
        }

        // Build execution context from request
        $context = $this->buildExecutionContext($request, $settings);

        $startTime = hrtime(true);
        $result = $this->toolExecutionService->execute($toolName, $input, $context);
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Log the invocation
        $this->logInvocation($request, $toolName, $context, $result->success, $result->error, $durationMs);

        return $this->jsonResponse($result->toArray(), $result->success ? 200 : 500);
    }

    private function logInvocation(
        ServerRequestInterface $request,
        string $toolName,
        ExecutionContext $context,
        bool $success,
        string $errorMessage,
        int $durationMs,
    ): void {
        $ipHash = ClientFingerprint::ipHash($request);
        $userAgent = preg_replace('/[^\x20-\x7E]/', '', mb_substr($request->getHeaderLine('User-Agent'), 0, 255)) ?? '';

        $this->invocationLogger->log(
            toolName: $toolName,
            userAgent: $userAgent,
            ipHash: $ipHash,
            siteIdentifier: $context->siteIdentifier,
            rootPageId: $context->rootPageId,
            success: $success,
            durationMs: $durationMs,
            errorMessage: $errorMessage,
        );
    }

    private function validateNonce(string $nonce, ServerRequestInterface $request): bool
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $nonce)) {
            return false;
        }

        $cacheKey = 'nonce_' . $nonce;
        $cached = $this->cache->get($cacheKey);
        if (false === $cached) {
            return false;
        }

        $data = json_decode((string) $cached, true);
        if (!is_array($data)) {
            return false;
        }

        // Validate client fingerprint
        $expectedFingerprint = $this->buildClientFingerprint($request);
        if (!isset($data['fingerprint']) || !hash_equals($data['fingerprint'], $expectedFingerprint)) {
            return false;
        }

        return true;
    }

    private function buildClientFingerprint(ServerRequestInterface $request): string
    {
        return ClientFingerprint::of($request);
    }

    private function checkRateLimit(string $fingerprint): bool
    {
        $rateLimitKey = 'ratelimit_' . substr($fingerprint, 0, 32);

        $current = $this->cache->get($rateLimitKey);
        $count = false !== $current ? (int) $current : 0;

        if ($count >= self::RATE_LIMIT_MAX_REQUESTS) {
            return false;
        }

        // Note: TYPO3 cache API resets TTL on each set() — no atomic increment available.
        // This is acceptable as the sliding window provides a soft rate limit.
        $this->cache->set($rateLimitKey, (string) ($count + 1), ['webmcp_ratelimit'], self::RATE_LIMIT_WINDOW_SECONDS);

        return true;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function buildExecutionContext(ServerRequestInterface $request, array $settings): ExecutionContext
    {
        $site = $request->getAttribute('site');
        $rootPageId = $site instanceof Site ? $site->getRootPageId() : 1;
        $siteIdentifier = $site instanceof Site ? $site->getIdentifier() : '';

        $language = $request->getAttribute('language');
        $languageId = 0;
        if ($language instanceof SiteLanguage) {
            $languageId = $language->getLanguageId();
        }

        return new ExecutionContext(
            rootPageId: $rootPageId,
            languageId: $languageId,
            siteIdentifier: $siteIdentifier,
            settings: $settings,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $statusCode): ResponseInterface
    {
        $body = $this->streamFactory->createStream(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($body);
    }
}
