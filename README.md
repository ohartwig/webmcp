# WebMCP — TYPO3 Extension

[![TYPO3 13.4 / 14](https://img.shields.io/badge/TYPO3-13.4%20%20--%20%2014.x-orange.svg)](https://get.typo3.org/)
[![TYPO3 13.4 / 14](https://img.shields.io/badge/TYPO3-13.4%20%20--%20%2014.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)

[Web Model Context Protocol (WebMCP)](https://webmachinelearning.github.io/webmcp/) integration for TYPO3.
Allows AI agents to interact with TYPO3 websites in a structured way via `document.modelContext`.

## Features

- **Automatic tool registration** — Built-in tools for search, navigation and page content
- **Extensible** — Add custom tools via `ToolProviderInterface` + `ToolHandlerInterface`
- **Secure** — CSRF nonce, SecureContext check, input validation, frontend user group access control
- **Per-site configurable** — Site Sets with individual tool toggles
- **Cache-friendly** — JS injection via `AssetCollector`, works with full-page cache

## Installation

```bash
composer require koh/webmcp
```

## Configuration

### Enable the Site Set

Include the site set `koh/webmcp` in your site configuration.

### Settings

In `config/sites/<site>/settings.yaml`:

```yaml
webmcp:
  enabled: true
  apiBasePath: '/_webmcp/api'
  bridge:
    enabled: true
    widget:
      # 'always' (default) shows the connection trigger to every visitor.
      # 'optin' keeps the bridge loaded and connectable but hides the trigger
      # until ?webmcp is in the URL or window.webmcp.showWidget() is called —
      # what a customer-facing site wants: the capability without the blue
      # square in the corner. The choice is remembered for the session.
      visibility: always
  # Chrome Origin Trial token — see "Browser Requirements" below
  originTrialToken: ''
  # Log registration failures to the browser console (development only)
  debug: false
  tools:
    search:
      enabled: true
      maxResults: 20
    navigation:
      enabled: true
      maxDepth: 4
    page-content:
      enabled: true
```

## Browser Requirements

The native WebMCP API (`document.modelContext`) is gated on several conditions. When any
of them is not met, tool registration silently does nothing — the bridge widget keeps
working, so the failure is easy to miss.

- **Secure context.** The API is `[SecureContext]`; it is unavailable over plain HTTP
  (`localhost` counts as secure).
- **Policy-controlled feature `tools`.** The default allowlist is `['self']`. A restrictive
  `Permissions-Policy` header that omits `tools` disables the API for the whole document,
  and in an iframe the embedding page must opt in explicitly:

  ```html
  <iframe src="https://example.org/" allow="tools"></iframe>
  ```

- **Origin Trial (Chrome 149–156).** Outside the trial `document.modelContext` is
  `undefined` on real sites. Register the origin at
  [Chrome Origin Trials](https://developer.chrome.com/origintrials/) and put the token into
  `webmcp.originTrialToken`; the extension then renders
  `<meta http-equiv="origin-trial" content="…">` into the `<head>`.

Implementation status at the time of writing (spec draft 2026-07-21): Chrome 149+ behind
the origin trial, Edge behind a flag, Firefox and Safari observing.

### Entry point

The 2026-07-21 Community Draft moved the entry point from `navigator.modelContext` to
`document.modelContext` and dropped `ModelContext.provideContext()`. The extension reads
`document.modelContext` and falls back to `navigator.modelContext` for Chrome 149, which
shipped the old location.

### Consent changes at runtime

Tools registered with a consent requirement are re-evaluated when consent changes:
Klaro via `klaro.getManager().watch()`, Cookiebot via its `CookiebotOnConsentReady` /
`CookiebotOnAccept` / `CookiebotOnDecline` events. With the `native` provider, update the
consent object and dispatch the event yourself:

```js
window.__webmcp_consent = { functional: true };
window.dispatchEvent(new Event('webmcp:consent-changed'));
```

Withdrawing consent aborts the tool's `AbortSignal`, which unregisters it from the agent.

## Built-in Tools

| Tool | Description | Input |
|------|------------|-------|
| `search` | Full-text search across pages and content elements | `{ query: string, limit?: int }` |
| `navigation` | Page tree as structured JSON | `{ rootPageId?: int, maxDepth?: int }` |
| `page-content` | Structured content of a specific page | `{ pageId?: int, slug?: string }` |

In addition, every registered context is exposed as a read-only tool named
`get-<context>` (e.g. `get-page-meta`, `get-site-info`) taking no input. Since
`provideContext()` was removed from the spec, this is the only way context data still
reaches native agents. These tools appear in the JS config payload and in the
`/.well-known` discovery endpoints like any other tool.

In the browser they resolve from the context data already contained in the config
payload — no API round-trip. Page-scoped contexts such as `page-meta` are therefore
only available to browser agents; the API middleware runs before TYPO3's page resolver
and cannot rebuild them, so they are not advertised in the discovery endpoints either.

All tools respect TYPO3 access restrictions (`fe_group`, `starttime`, `endtime`, `hidden`). Only content visible to the current visitor (anonymous or logged-in) is returned. The set of readable pages is computed once per site and visitor group and cached for five minutes in the `webmcp` cache, which belongs to the `pages` group, so a content change flushes it.

Calls are rate-limited per client address (60 per minute, IPv6 addresses by their /64) and per site (600 per minute), counted atomically in `tx_webmcp_rate_limit`. The User-Agent is not part of the key — it is whatever the caller says it is.

### What is advertised is what runs

The API executes only what the site advertises. `webmcp.tools.<name>.enabled`, the
provider key (`webmcp.tools.form.enabled` for every `form-*` tool), and the page TSconfig
override of the page the nonce was minted on are applied by the middleware before any
handler runs; a tool switched off anywhere answers `404` at `/_webmcp/api/<name>`. A
handler with no provider advertising it is not reachable at all. The backend module lists
every registered tool provider, including those other extensions bring, so a provider a
site never meant to expose can be switched off by its key.

The body of a call is validated against the tool's advertised `inputSchema` before the
handler runs — types, required and additional properties, enum, string lengths, numeric
bounds, array sizes, nested objects. A body that does not conform answers `400` with the
violated paths and rules; the values a caller sent are never echoed. Handlers can rely on
the shape they advertise. Keywords outside that subset (`pattern`, `format`, `$ref`) are
ignored, so an unusual schema loosens the check rather than breaking the tool.

A tool with `consent.required: true` runs only when the request carries
`X-WebMcp-Consent: <category>` with the configured category. The init script sends that
header after its own consent check passed, so a caller that bypasses the page cannot
bypass the gate; it is the visitor's page asserting the visitor's consent, not a proof
the server could verify on its own.

## Audit Log and Retention

Every tool call is logged to `tx_webmcp_tool_log`: tool, site, success, duration, a
keyed hash of the caller's address (HMAC with a key derived from the installation's
`encryptionKey` — it links one caller's entries, it does not reveal the address), and
the User-Agent reduced to its product tokens (`Mozilla/5.0 Chrome/120.0 Safari/537.36`;
the parenthesised comments with OS and device details are dropped). That is still a
visitor record, so it has a retention.

The retention is the extension setting `logRetentionDays` (Admin Tools → Settings →
Extension Configuration → webmcp), 30 days by default. Two things enforce it:

- `vendor/bin/typo3 webmcp:cleanup-log` deletes older entries. Schedule it daily, either
  as a Scheduler task of type *Execute console commands* or from cron:

  ```crontab
  15 3 * * * /path/to/vendor/bin/typo3 webmcp:cleanup-log
  ```

  `--days=<n>` overrides the configured retention for one run.
- The API purges opportunistically, on about one call in a hundred, so the retention
  holds on an installation where nobody set up the scheduler. The scheduled command is
  the proper way; this is the floor under it.

## Creating Custom Tools

### 1. Implement a ToolProvider

```php
<?php

use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;

final class ShopToolProvider implements ToolProviderInterface
{
    public function getIdentifier(): string
    {
        return 'shop';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                name: 'product-search',
                description: 'Search products in the shop',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'category' => ['type' => 'string'],
                    ],
                    'required' => ['query'],
                ],
                annotations: new ToolAnnotations(readOnlyHint: true),
            ),
        ];
    }
}
```

### 2. Implement a ToolHandler

```php
<?php

use Koh\WebMcp\Domain\Contract\ToolHandlerInterface;
use Koh\WebMcp\Domain\Model\ToolResult;
use Psr\Http\Message\ServerRequestInterface;

final class ProductSearchHandler implements ToolHandlerInterface
{
    public function getToolName(): string
    {
        return 'product-search';
    }

    public function execute(array $input, ServerRequestInterface $request): ToolResult
    {
        // Implement product search...
        return ToolResult::success(['products' => $results]);
    }
}
```

Both classes are automatically discovered via `_instanceof` tags in `Services.yaml` — no additional configuration required.

## How It Works

1. When a page is rendered, the extension injects JavaScript via `AssetCollector`
2. The JavaScript registers all enabled tools via `document.modelContext.registerTool(tool, { signal })`
3. When an AI agent calls a tool, the browser sends a `POST` request to `/_webmcp/api/{toolName}`
4. The PSR-15 middleware validates the request (CSRF nonce, input) and delegates to the appropriate ToolHandler
5. The result is returned as JSON

## Requirements

- TYPO3 13.4 or 14.0
- PHP 8.2 or higher
- HTTPS (SecureContext required)

## License

MIT — Copyright (c) Moselwal Digitalagentur GmbH
