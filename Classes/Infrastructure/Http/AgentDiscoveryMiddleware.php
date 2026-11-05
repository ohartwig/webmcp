<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

use Koh\WebMcp\Application\Contract\ConsentCheckInterface;
use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Model\DiscoveryPath;
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
 * - /.well-known/agent-skills/index.json (Agent Skills Discovery v0.2.0 index)
 * - /.well-known/agent-skills/{name}/SKILL.md (the skill artifacts it names)
 * - /.well-known/ard.json              (Agentic Resource Discovery catalogue)
 * - /.well-known/ai-catalog.json       (the same catalogue under its earlier name)
 *
 * Also adds agent-useful Link headers to all HTML responses.
 *
 * `/.well-known/mcp.json` is deliberately not served. No MCP specification
 * defines that path: server cards (SEP-2127) are found through the AI
 * Catalog / ARD manifest, and a document under a name nobody specifies can
 * only be judged malformed by whoever probes it.
 */
final readonly class AgentDiscoveryMiddleware implements MiddlewareInterface
{
    private const string WEBMCP_VERSION = '1.0.0';

    /**
     * ARD names the catalogue `ard.json`; the earlier `ai-catalog.json` is still
     * what most crawlers probe first. Both are served, because a catalogue that
     * only answers under the name a client does not try is not discoverable.
     */
    private const string ARD_PATH = DiscoveryPath::ARD;

    private const string ARD_LEGACY_PATH = DiscoveryPath::ARD_LEGACY;

    private const string AGENT_SKILLS_SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    public function __construct(
        private ToolRegistry $toolRegistry,
        private ConsentCheckInterface $consentCheck,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private A2aAgentCard $a2aAgentCard = new A2aAgentCard(),
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        return match (true) {
            '/.well-known/mcp/server-card.json' === $path => $this->handleMcpServerCard($site),
            '/.well-known/api-catalog' === $path => $this->handleApiCatalog($site),
            DiscoveryPath::AGENT_SKILLS_INDEX === $path => $this->handleAgentSkillsIndex($site),
            1 === preg_match('#^/\.well-known/agent-skills/([^/]+)/SKILL\.md$#', $path, $match) => $this->handleAgentSkillArtifact($match[1], $site),
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
     * Entries are built from what this installation actually serves — a
     * catalogue that promises an endpoint returning 404 is worse than a
     * shorter one, because the agent has already spent the request. The A2A
     * Agent Card is listed exactly when business-agent serves it for this
     * site (see A2aAgentCard).
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

        if ($this->a2aAgentCard->isServedFor($site)) {
            $entries[] = $this->ardEntry(
                identifier: 'urn:air:' . $publisher . ':agent:a2a',
                displayName: $title . ' Agent',
                type: 'application/a2a-agent-card+json',
                url: $this->a2aAgentCard->url($site),
                description: 'A2A Agent Card of the agent that searches this site\'s published pages.',
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

    /**
     * Agent Skills Discovery v0.2.0 (cloudflare/agent-skills-discovery-rfc):
     * every entry names a `SKILL.md` artifact this middleware serves and the
     * SHA-256 of its exact bytes. A tool whose name is not a valid skill name
     * is left out; with WebMCP disabled the API refuses every call, so the
     * index is then empty rather than a list of skills that cannot work.
     */
    private function handleAgentSkillsIndex(Site $site): ResponseInterface
    {
        $skills = [];
        foreach ($this->agentSkills($site) as $document) {
            $skills[] = [
                'name' => $document->name(),
                'type' => 'skill-md',
                'description' => $document->description(),
                'url' => sprintf(DiscoveryPath::AGENT_SKILL_MD, $document->name()),
                'digest' => $document->digest(),
            ];
        }

        return $this->withCors($this->jsonResponse([
            '$schema' => self::AGENT_SKILLS_SCHEMA,
            'skills' => $skills,
        ], 200, 'application/json'));
    }

    private function handleAgentSkillArtifact(string $name, Site $site): ResponseInterface
    {
        foreach ($this->agentSkills($site) as $document) {
            if ($document->name() === $name) {
                return $this->withCors(
                    $this->responseFactory->createResponse(200)
                        ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
                        ->withHeader('X-Content-Type-Options', 'nosniff')
                        ->withHeader('Cache-Control', 'public, max-age=3600')
                        ->withBody($this->streamFactory->createStream($document->render())),
                );
            }
        }

        return $this->responseFactory->createResponse(404)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * @return list<AgentSkillDocument>
     */
    private function agentSkills(Site $site): array
    {
        $webmcpSettings = $site->getSettings()->getAll()['webmcp'] ?? [];
        if (!is_array($webmcpSettings) || !((bool) ($webmcpSettings['enabled'] ?? false))) {
            return [];
        }

        $base = rtrim((string) $site->getBase(), '/');
        $title = trim((string) ($site->getConfiguration()['websiteTitle'] ?? ''));
        $siteTitle = '' !== $title ? $title : $site->getBase()->getHost();
        $apiBasePath = $webmcpSettings['apiBasePath'] ?? '/_webmcp/api';

        $documents = [];
        foreach ($this->toolRegistry->getTools(siteSettings: $webmcpSettings) as $tool) {
            if (!AgentSkillDocument::isPublishable($tool)) {
                continue;
            }
            $documents[] = new AgentSkillDocument(
                tool: $tool,
                siteTitle: $siteTitle,
                baseUrl: $base,
                apiBasePath: is_string($apiBasePath) ? $apiBasePath : '/_webmcp/api',
                consent: $this->consentCheck->getConsentRequirement($tool->name, $webmcpSettings),
            );
        }

        return $documents;
    }

    private function withCors(ResponseInterface $response): ResponseInterface
    {
        // Public, read-only documents: the spec asks for CORS so that
        // browser-based clients can read them.
        return $response->withHeader('Access-Control-Allow-Origin', '*');
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
     * auth.md (https://github.com/workos/auth.md, profile v0.6.0) — the human-
     * and agent-readable recipe for obtaining credentials, served from the site
     * root. It describes the anonymous registration and claim ceremony that
     * mcp_server implements, step for step as the profile numbers them.
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

            This service follows the auth.md profile, version 0.6.0
            (https://github.com/workos/auth.md). It is for autonomous agents that
            want to call the MCP endpoint at `{$base}/mcp`. Follow the steps in
            order.

            ## Step 1 — Discover

            - `{$base}/.well-known/oauth-protected-resource` — the protected resource
            - `{$base}/.well-known/oauth-authorization-server` — `token_endpoint`,
              `revocation_endpoint`, `grant_types_supported`, `jwks_uri` and the
              `agent_auth` block (`identity_endpoint`, `claim_endpoint`,
              `identity_types_supported`)

            ## Step 2 — Pick a method

            Only `anonymous` is offered. There is no trusted ID-JAG provider and no
            `service_auth`; both answer `issuer_not_enabled` and
            `service_auth_not_enabled`.

            **Every access token here traces back to a human editor of this site**
            and is bounded by that editor's permissions. An agent may register on its
            own, before anyone has vouched for it — but until an editor claims it,
            the registration grants nothing at all.

            ## Step 3 — Register

                POST {$base}/mcp_oauth/agent/identity
                Content-Type: application/json

                {"type": "anonymous", "client_name": "Your agent"}

            `client_name` is optional; the editor sees it when claiming. The response:

                {
                  "registration_id": "reg_...",
                  "registration_type": "anonymous",
                  "pre_claim_scopes": [],
                  "claim_url": "{$base}/mcp_oauth/agent/identity/claim",
                  "claim_token": "clm_...",
                  "claim_token_expires": "...",
                  "post_claim_scopes": ["mcp:read", "mcp:write"]
                }

            `pre_claim_scopes` is empty, so there is **no `identity_assertion` before
            the claim** — nothing to exchange, nothing that reaches `/mcp`. Go to
            Step 4. Hold `claim_token` in memory; it is returned once. Unclaimed
            registrations expire after one hour, and registering is rate-limited per
            client address (`429 rate_limited`).

            ## Step 4 — Claim ceremony

            ### 4a. Start an attempt

                POST {$base}/mcp_oauth/agent/identity/claim
                Content-Type: application/json

                {"claim_token": "clm_...", "email": "editor@example.com"}

            `email` is optional. When given, only the backend user with that address
            can complete the claim. The response carries a `claim_attempt` block with
            `user_code` (six digits), `verification_uri`, `expires_in` (600) and
            `interval` (5).

            ### 4b. Hand off to the user

            > Open this link, sign in to the TYPO3 backend, and enter this code: **123456**

            The editor opens `verification_uri`, signs in and types the code. The code
            goes into that page — not back to you. Codes are single-use; five wrong
            entries burn the attempt.

            ### 4c. Poll for completion

                POST {$base}/mcp_oauth/token
                Content-Type: application/x-www-form-urlencoded

                grant_type=urn:workos:agent-auth:grant-type:claim&claim_token=clm_...

            `authorization_pending` while waiting, `slow_down` when polling faster
            than `interval`, `expired_token` when the code or the registration ran
            out — then call Step 4a again with the same `claim_token`; if that
            answers `410 claim_expired`, start over at Step 3. On success you get a
            bearer `access_token` (`expires_in` 3600, scope `mcp:read mcp:write`)
            plus an `identity_assertion` and `assertion_expires`. The claim token is
            spent by that answer.

            ## Step 5 — Exchange the assertion

            When the access token expires, exchange the `identity_assertion`
            (RFC 7523):

                POST {$base}/mcp_oauth/token
                Content-Type: application/x-www-form-urlencoded

                grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=<identity_assertion>&resource={$base}/mcp

            There is no refresh token; the assertion is the refresh path. It is an
            EdDSA-signed JWT, verifiable with the key at
            `{$base}/mcp_oauth/jwks`. `invalid_grant` means the assertion expired or
            the registration was revoked — start over at Step 3.

            ## Step 6 — Use the access_token

                Authorization: Bearer <access_token>

            The header is the only accepted position; tokens in query strings are
            refused. What a token may do is decided by the permissions of the editor
            who claimed the agent.

            ## Revocation

            - **You**: `POST {$base}/mcp_oauth/revoke` with `token=<access_token>`
              (RFC 7009) ends that access token only; your `identity_assertion` keeps
              working.
            - **The editor**: revoking the agent in the TYPO3 backend
              ({$base}/typo3/module/user/mcp-server) ends the registration, its
              assertion and every access token derived from it. You notice when
              Step 5 answers `invalid_grant`; treat that as revoked-by-human.

            ## Interactive clients

            Clients that can open a browser for the editor may instead use OAuth 2.1
            with RFC 7591 dynamic client registration (`{$base}/mcp_oauth/register`;
            loopback or operator-allow-listed HTTPS `redirect_uris`) and the
            authorization code flow with mandatory PKCE `S256`
            (`{$base}/mcp_oauth/authorize`, then `{$base}/mcp_oauth/token`). The
            editor signs in and consents during the authorize step.

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
