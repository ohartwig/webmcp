<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

use Koh\WebMcp\Domain\Model\PageToolConfiguration;

/**
 * The per-page tool overrides an editor sets in page TSconfig
 * (`webmcp.tools.<name>.enabled = 0`).
 *
 * Presentation reads them when it advertises tools on a page. The API's
 * nonce endpoint reads them again for the page a nonce is requested for and
 * binds them to that nonce, so that the API middleware — which runs before
 * any page is resolved — applies the same overrides when the tool is called.
 */
interface PageToolConfigurationProviderInterface
{
    public function getConfigurationForPage(int $pageId): PageToolConfiguration;
}
