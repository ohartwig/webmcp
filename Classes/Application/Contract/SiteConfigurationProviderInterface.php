<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

/**
 * Resolves the effective WebMCP settings of one site.
 *
 * Takes a site identifier rather than a request on purpose. Both callers --
 * the API middleware (Infrastructure) and the JavaScript listener
 * (Presentation) -- may depend on Application but not on each other, so this
 * contract has to live here; and a contract that lives here cannot name
 * PSR-7. Passing the identifier keeps the HTTP layer on the caller's side of
 * the boundary, where it already is.
 *
 * An empty or unknown identifier yields the defaults rather than an error:
 * a request without a resolved site is a normal condition (early middleware,
 * CLI), not a failure.
 */
interface SiteConfigurationProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getSettings(string $siteIdentifier): array;
}
