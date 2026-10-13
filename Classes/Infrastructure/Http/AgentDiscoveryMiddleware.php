<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Serves agent discovery endpoints:
 * - /.well-known/mcp/server-card.json  (MCP Server Card)
 * - /.well-known/api-catalog           (RFC 9727 API Catalog)
 * - /.well-known/agent-skills/index.json (Agent Skills index)
 * - /.well-known/ard.json              (Agentic Resource Discovery catalogue)
 * - /.well-known/ai-catalog.json       (the same catalogue under its earlier name)
 *
 * Also adds agent-useful Link headers to all HTML responses.
 */
final readonly class AgentDiscoveryMiddleware implements MiddlewareInterface
{
    private const string WEBMCP_VERSION = '1.0.0';

    /**
     * ARD names the catalogue `ard.json`; the earlier `ai-catalog.json` is still
     * what most crawlers probe first. Both are served, because a catalogue that
     * only answers under the name a client does not try is not discoverable.
     */
    private const string ARD_PATH = '/.well-known/ard.json';

    private const string ARD_LEGACY_PATH = '/.well-known/ai-catalog.json';

    public function __construct(
        private ToolRegistry $toolRegistry,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        return match (true) {
            '/.well-known/mcp.json' === $path => $this->handleMcpDiscovery($request, $site),
            '/.well-known/mcp/server-card.json' === $path => $this->handleMcpServerCard($site),
            '/.well-known/api-catalog' === $path => $this->handleApiCatalog($site),
            '/.well-known/agent-skills/index.json' === $path => $this->handleAgentSkillsIndex($request, $site),
            self::ARD_PATH === $path, self::ARD_LEGACY_PATH === $path => $this->handleArdCatalog($site),
            str_starts_with($path, '/.well-known/agent-skills/') && str_ends_with($path, '.json') => $this->handleAgentSkillDetail($path, $request, $site),
            '/.well-known/http-message-signatures-directory' === $path => $this->handleWebBotAuth(),
            '/auth.md' === $path && $this->hasMcpServer() => $this->handleAuthMd($site),
            default => $this->addLinkHeaders($handler->handle($request), $site),
        };
    }

    private function handleMcpServerCard(Site $site): ResponseInterface
    {
        $base = rtrim((string) $site->getBase(), '/');
        $title = $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier();

        $card = [
            'serverInfo' => [
                'name' => $title . ' MCP Server',
                'version' => self::WEBMCP_VERSION,
            ],
            'description' => 'TYPO3 CMS content access via MCP — search, navigation, page content',
            'url' => $base . '/mcp',
            'transport' => [
                'type' => 'streamable-http',
            ],
            'capabilities' => [
                'tools' => true,
            ],
        ];

        return $this->jsonResponse($card, 200, 'application/json');
    }

    private function handleApiCatalog(Site $site): ResponseInterface
    {
        $base = rtrim((string) $site->getBase(), '/');

        $catalog = [
            'linkset' => [
                [
                    'anchor' => $base . '/.well-known/api-catalog',
                    'item' => [
                        [
                            'href' => $base . '/mcp',
                            'title' => 'MCP Server (Model Context Protocol)',
                            'type' => 'application/json',
                        ],
                        [
                            'href' => $base . '/_webmcp/api',
                            'title' => 'WebMCP API (browser-side tools)',
                            'type' => 'application/json',
                        ],
                    ],
                ],
            ],
        ];

        return $this->jsonResponse(
            data: $catalog,
            statusCode: 200,
            contentType: 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
        );
    }

    /**
     * The ARD catalogue: one place that names every agentic resource this site
     * publishes, so an agent does not have to guess at well-known paths.
     *
     * Entries are built from what this installation actually serves. The A2A
     * card is only listed when the extension that answers for it is loaded —
     * a catalogue that promises an endpoint returning 404 is worse than a
     * shorter one, because the agent has already spent the request.
     */
    private function handleArdCatalog(Site $site): ResponseInterface
    {
        $base = rtrim((string) $site->getBase(), '/');
        $publisher = $site->getBase()->getHost();
        $title = $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier();

        $entries = [];

        // ARD's representativeQueries route a user's question to a resource, so
        // they belong on the resources an agent can actually invoke. On a
        // linkset or a Markdown summary they would be noise.
        $queries = $this->representativeQueries($site);

        $entries[] = $this->ardEntry(
            identifier: 'urn:air:' . $publisher . ':server:mcp',
            displayName: $title . ' MCP Server',
            type: 'application/mcp-server-card+json',
            url: $base . '/.well-known/mcp/server-card.json',
            description: 'Read access to this site\'s content over the Model Context Protocol.',
            representativeQueries: $queries,
        );

        if ($this->hasBusinessAgent()) {
            $entries[] = $this->ardEntry(
                identifier: 'urn:air:' . $publisher . ':agent:a2a',
                displayName: $title . ' Agent',
                type: 'application/a2a-agent-card+json',
                url: $base . '/.well-known/agent-card.json',
                description: 'Agent-to-Agent card describing what this agent can be asked to do.',
                representativeQueries: $queries,
            );
        }

        $entries[] = $this->ardEntry(
            identifier: 'urn:air:' . $publisher . ':api:catalog',
            displayName: $title . ' API Catalog',
            type: 'application/linkset+json',
            url: $base . '/.well-known/api-catalog',
            description: 'RFC 9727 catalogue of the machine-callable endpoints this site offers.',
        );

        $entries[] = $this->ardEntry(
            identifier: 'urn:air:' . $publisher . ':knowledge:llms',
            displayName: $title . ' — site summary for agents',
            type: 'text/markdown',
            url: $base . '/llms.txt',
            description: 'Markdown summary of the site, written to be read by an agent.',
        );

        // ARD §4.1: the manifest is an envelope, not a bare list. specVersion
        // and the host object are what a registry validates before it reads a
        // single entry - isitagentready.com scored the catalogue as missing
        // for want of exactly these two keys. The host identifier is the
        // domain (ARD §4.3 allows "DID or domain"), which is also the
        // publisher every entry's URN is anchored to.
        return $this->jsonResponse([
            'specVersion' => '1.0',
            'host' => [
                'displayName' => $title,
                'identifier' => $publisher,
            ],
            'entries' => $entries,
        ], 200, 'application/json');
    }

    /**
     * @param list<string> $representativeQueries
     *
     * @return array<string, mixed>
     */
    private function ardEntry(
        string $identifier,
        string $displayName,
        string $type,
        string $url,
        string $description,
        array $representativeQueries = [],
    ): array {
        $entry = [
            'identifier' => $identifier,
            'displayName' => $displayName,
            'type' => $type,
            'url' => $url,
            'description' => $description,
        ];

        if ([] !== $representativeQueries) {
            $entry['representativeQueries'] = $representativeQueries;
        }

        return $entry;
    }

    /**
     * ARD recommends two to five natural-language queries per entry, and they
     * decide how the entry ranks in an agent registry. They are left empty
     * until an editor writes them: a generated query describes the shape of
     * the site rather than what anyone would actually ask it, and a wrong
     * example is worse for ranking than none.
     *
     * @return list<string>
     */
    private function representativeQueries(Site $site): array
    {
        $configured = $site->getSettings()->getAll()['webmcp']['ard']['representativeQueries'] ?? '';
        if (!is_string($configured) || '' === trim($configured)) {
            return [];
        }

        $lines = preg_split('/\R/', $configured);
        if (false === $lines) {
            return [];
        }

        $queries = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $queries[] = $line;
            }
        }

        return array_slice($queries, 0, 5);
    }

    private function hasBusinessAgent(): bool
    {
        return ExtensionManagementUtility::isLoaded('business_agent');
    }

    private function handleAgentSkillsIndex(ServerRequestInterface $request, Site $site): ResponseInterface
    {
        $settings = $site->getSettings()->getAll();
        $webmcpSettings = $settings['webmcp'] ?? [];
        $tools = $this->toolRegistry->getTools(siteSettings: $webmcpSettings);

        $skills = [];
        foreach ($tools as $tool) {
            $skill = [
                'name' => $tool->name,
                'type' => 'webmcp-tool',
                'description' => $tool->description,
            ];
            if (null !== $tool->title && '' !== trim($tool->title)) {
                $skill['title'] = $tool->title;
            }
            $skills[] = $skill;
        }

        $index = [
            '$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
            'skills' => $skills,
        ];

        return $this->jsonResponse($index, 200, 'application/json');
    }

    private function handleMcpDiscovery(ServerRequestInterface $request, Site $site): ResponseInterface
    {
        $base = rtrim((string) $site->getBase(), '/');
        $settings = $site->getSettings()->getAll();
        $webmcpSettings = $settings['webmcp'] ?? [];
        $tools = $this->toolRegistry->getTools(siteSettings: $webmcpSettings);

        // Per W3C WebMCP Community Draft: tool manifest includes name,
        // description, inputSchema, optional title, and annotations
        // (readOnlyHint + untrustedContentHint). Use ToolDefinition::toArray()
        // so the discovery payload stays in sync with the JS-side manifest.
        $toolList = array_map(static fn($t) => $t->toArray(), $tools);

        $apiBasePath = $webmcpSettings['apiBasePath'] ?? '/_webmcp/api';

        $discovery = [
            'mcp' => [
                'name' => $site->getIdentifier(),
                'version' => self::WEBMCP_VERSION,
                'transport' => [
                    'type' => 'streamable_http',
                    'endpoint' => $base . $apiBasePath,
                ],
                'tools' => $toolList,
            ],
        ];

        return $this->jsonResponse($discovery, 200, 'application/json');
    }

    private function handleAgentSkillDetail(string $path, ServerRequestInterface $request, Site $site): ResponseInterface
    {
        $skillName = basename($path, '.json');
        $settings = $site->getSettings()->getAll();
        $webmcpSettings = $settings['webmcp'] ?? [];
        $tools = $this->toolRegistry->getTools(siteSettings: $webmcpSettings);
        $base = rtrim((string) $site->getBase(), '/');
        $apiBasePath = $webmcpSettings['apiBasePath'] ?? '/_webmcp/api';

        foreach ($tools as $tool) {
            if ($tool->name === $skillName) {
                $detail = $tool->toArray() + [
                    'endpoint' => $base . $apiBasePath . '/' . $tool->name,
                    'examples' => [
                        [
                            'input' => $this->getExampleInput($tool),
                            'description' => 'Example usage of ' . $tool->name,
                        ],
                    ],
                ];

                return $this->jsonResponse($detail, 200, 'application/json');
            }
        }

        return $this->responseFactory->createResponse(404)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * @return array<string, mixed>
     */
    private function getExampleInput(ToolDefinition $tool): array
    {
        return match ($tool->name) {
            'search' => ['query' => 'Leistungen', 'limit' => 5],
            'navigation' => ['maxDepth' => 2],
            'page-content' => ['slug' => '/'],
            default => [],
        };
    }

    private function handleWebBotAuth(): ResponseInterface
    {
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $seed = hash('sha256', $encryptionKey . '|webmcp-bot-auth', true);
        $keyPair = sodium_crypto_sign_seed_keypair($seed);
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        $jwks = [
            'keys' => [
                [
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
                ],
            ],
        ];

        return $this->jsonResponse($jwks, 200, 'application/json');
    }

    /**
     * auth.md (https://workos.com/auth-md) — the human- and agent-readable
     * recipe for obtaining credentials, served from the site root.
     *
     * Every endpoint named here belongs to the mcp_server extension, which is
     * why this is gated on hasMcpServer(): without it the OAuth routes do not
     * exist and publishing this document would send agents to 404s.
     */
    private function handleAuthMd(Site $site): ResponseInterface
    {
        $base = rtrim((string) $site->getBase(), '/');
        $title = $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier();

        $markdown = <<<MARKDOWN
            # auth.md

            Agent registration and authentication for **{$title}** ({$base}).

            This document is for autonomous agents that want to call the MCP
            endpoint at `{$base}/mcp`. Everything an agent needs is also available
            as machine-readable metadata:

            - `{$base}/.well-known/oauth-protected-resource` — the protected resource
            - `{$base}/.well-known/oauth-authorization-server` — endpoints and the `agent_auth` block

            ## Audience

            The protected resource is `{$base}/mcp`. An agent may obtain an
            **identity** on its own, before anyone has vouched for it. What it may
            **do** is a separate question: every token traces back to a human editor
            of this site and is bounded by that editor's permissions.

            So there is no machine-only *authority* here — but there is no
            requirement to have a human present when the agent first appears either.

            ## Anonymous identity

            Ask for an identity without credentials of any kind:

                POST {$base}/mcp_oauth/agent/identity
                Content-Type: application/json

                {"client_name": "Your agent"}

            The response carries `identity_id`, a bearer `credential`, a `claim_uri`
            and `"authorized": false`. That last field is the important one: **the
            credential grants nothing yet**. It is stored apart from access tokens
            and the MCP endpoint cannot see it, so sending it to `/mcp` fails no
            matter how it is formatted.

            Give the claim code to a backend editor. When they claim the identity
            in the TYPO3 backend, the agent gains an access token carrying that
            editor's permissions — the same access the consent screen below would
            have produced, reached in the other order.

            Unclaimed identities expire after an hour, and issuing is rate-limited
            per address. An identity nobody claims is meant to be thrown away.

            ## Registration

            Register as an OAuth client with RFC 7591 dynamic client registration:

                POST {$base}/mcp_oauth/register
                Content-Type: application/json

                {
                  "client_name": "Your agent",
                  "redirect_uris": ["http://127.0.0.1:<port>/callback"]
                }

            Registration is open, but `redirect_uris` are restricted. Accepted are
            loopback URIs (`127.0.0.1`, `::1`, `localhost`, any port — RFC 8252) for
            native and local agents, and HTTPS URIs explicitly allow-listed by the
            site operator. Any other URI is rejected with `invalid_redirect_uri`;
            ask the operator to allow-list it rather than retrying.

            ## Authorisation

            Run the authorization code flow. PKCE with `S256` is mandatory — a
            request without a code challenge is rejected.

                GET  {$base}/mcp_oauth/authorize
                     ?response_type=code&client_id=...&redirect_uri=...
                     &code_challenge=...&code_challenge_method=S256
                POST {$base}/mcp_oauth/token

            A human editor signs in and consents during the authorize step. Public
            clients may use `token_endpoint_auth_method=none`; PKCE carries the
            security guarantee.

            ## Using the credential

            The result is a bearer access token. Send it in the header — this is the
            only accepted position, tokens in query strings are refused:

                Authorization: Bearer <access_token>

            Declared scopes are `mcp:read` and `mcp:write`. They are informational:
            what a token may actually do is decided by the permissions of the editor
            who authorised it.

            ## Revocation

                POST {$base}/mcp_oauth/revoke

            RFC 7009. The token being revoked authenticates the call, so no client
            secret is required. Editors can revoke agent access at any time in the
            TYPO3 backend ({$base}/typo3/module/user/mcp-server); treat a sudden
            `401` as revoked-by-human and stop retrying.

            MARKDOWN;

        $body = $this->streamFactory->createStream($markdown);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withBody($body);
    }

    private function hasMcpServer(): bool
    {
        return ExtensionManagementUtility::isLoaded('mcp_server');
    }

    private function addLinkHeaders(ResponseInterface $response, Site $site): ResponseInterface
    {
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $base = rtrim((string) $site->getBase(), '/');

        return $response
            ->withAddedHeader('Link', '<' . $base . '/.well-known/api-catalog>; rel="api-catalog"')
            ->withAddedHeader('Link', '<' . $base . '/.well-known/mcp/server-card.json>; rel="mcp-server-card"')
            ->withAddedHeader('Link', '<' . $base . '/llms.txt>; rel="describedby"; type="text/markdown"')
            ->withAddedHeader('Link', '<' . $base . self::ARD_PATH . '>; rel="ard"')
            ->withAddedHeader('Link', '<' . $base . self::ARD_PATH . '>; rel="ai-catalog"');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $statusCode, string $contentType): ResponseInterface
    {
        $body = $this->streamFactory->createStream(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withBody($body);
    }
}
