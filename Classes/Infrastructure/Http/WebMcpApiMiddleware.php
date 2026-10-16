<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

use Koh\WebMcp\Application\Contract\ConsentCheckInterface;
use Koh\WebMcp\Application\Contract\LogRetentionInterface;
use Koh\WebMcp\Application\Contract\RateLimiterInterface;
use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use Koh\WebMcp\Application\Service\InputSchemaValidator;
use Koh\WebMcp\Application\Service\ToolExecutionService;
use Koh\WebMcp\Application\Service\ToolInvocationLogger;
use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\PageToolConfiguration;
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
    private const int MAX_BODY_SIZE = 65536;

    /**
     * Sent by the init script for a tool whose consent requirement its
     * consent check passed, carrying the category it granted. See the note
     * at the consent check below for what that does and does not prove.
     */
    private const string CONSENT_HEADER = 'X-WebMcp-Consent';

    public function __construct(
        private ToolExecutionService $toolExecutionService,
        private SiteConfigurationProviderInterface $siteConfigurationProvider,
        private ToolInvocationLogger $invocationLogger,
        private FrontendInterface $cache,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ToolRegistry $toolRegistry,
        private ConsentCheckInterface $consentCheck,
        private RateLimiterInterface $rateLimiter,
        private InputSchemaValidator $inputSchemaValidator,
        private LogRetentionInterface $logRetention,
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
        $nonceData = '' === $nonce ? null : $this->validateNonce($nonce, $request);
        if (null === $nonceData) {
            return $this->jsonResponse(['error' => 'Invalid or missing nonce'], 403);
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

        // What runs is what is advertised, decided by the same filter: the
        // site settings, and the page TSconfig overrides of the page the nonce
        // was minted on. A tool switched off there, or registered by a
        // provider the site never enabled, is unknown here — having a handler
        // is necessary, not sufficient.
        $pageConfig = new PageToolConfiguration($this->toolOverridesFrom($nonceData));
        $tool = $this->toolRegistry->findTool($toolName, $settings, $pageConfig);
        if (null === $tool || !$this->toolExecutionService->hasHandler($toolName)) {
            return $this->jsonResponse(['error' => 'Unknown tool'], 404);
        }

        // A consent-gated tool runs only when the caller states the granted
        // category. The init script sends it after its own consent check, so
        // this is the visitor's page asserting the visitor's consent — the
        // same trust level as the client-side gate, but now a caller that
        // bypasses the page (a stolen nonce, a hand-written request) cannot
        // run the tool without also claiming consent, and the claim is in the
        // audit trail of the request.
        $requirement = $this->consentCheck->getConsentRequirement($toolName, $settings);
        if ($requirement->required && !hash_equals($requirement->category, $request->getHeaderLine(self::CONSENT_HEADER))) {
            return $this->jsonResponse(['error' => 'Consent required'], 403);
        }

        // Rate limiting per caller and per site. Deliberately NOT per nonce
        // (a nonce is minted on every page render, so a caller who spent its
        // budget would simply reload) and NOT per fingerprint either: half of
        // that was the User-Agent, a header the caller chooses.
        if (!$this->rateLimiter->allow(ClientFingerprint::ip($request), $site instanceof Site ? $site->getIdentifier() : '')) {
            return $this->jsonResponse(['error' => 'Rate limit exceeded'], 429);
        }

        // The schema the tool advertises is the contract the handler may rely
        // on. Violations name path and rule only; a response never echoes input.
        $violations = $this->inputSchemaValidator->validate($tool->inputSchema, $input);
        if ([] !== $violations) {
            return $this->jsonResponse(['error' => 'Invalid input', 'details' => $violations], 400);
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
        $userAgent = UserAgentReducer::reduce($request->getHeaderLine('User-Agent'));

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

        // The retention holds even where no scheduler runs the cleanup command.
        $this->invocationLogger->maybePurge($this->logRetention->days());
    }

    /**
     * The nonce's cache entry when the nonce is valid for this caller, else null.
     *
     * @return array<string, mixed>|null
     */
    private function validateNonce(string $nonce, ServerRequestInterface $request): ?array
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $nonce)) {
            return null;
        }

        $cacheKey = 'nonce_' . $nonce;
        $cached = $this->cache->get($cacheKey);
        if (false === $cached) {
            return null;
        }

        $data = json_decode((string) $cached, true);
        if (!is_array($data)) {
            return null;
        }

        // Validate client fingerprint
        $expectedFingerprint = $this->buildClientFingerprint($request);
        if (!isset($data['fingerprint']) || !hash_equals($data['fingerprint'], $expectedFingerprint)) {
            return null;
        }

        return $data;
    }

    /**
     * The page TSconfig overrides the listener bound to the nonce. Anything
     * that is not a name-to-flag map is ignored rather than trusted.
     *
     * @param array<string, mixed> $nonceData
     *
     * @return array<string, bool>
     */
    private function toolOverridesFrom(array $nonceData): array
    {
        $overrides = [];
        foreach ((array) ($nonceData['toolOverrides'] ?? []) as $name => $enabled) {
            if (is_string($name)) {
                $overrides[$name] = (bool) $enabled;
            }
        }

        return $overrides;
    }

    private function buildClientFingerprint(ServerRequestInterface $request): string
    {
        return ClientFingerprint::of($request);
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
