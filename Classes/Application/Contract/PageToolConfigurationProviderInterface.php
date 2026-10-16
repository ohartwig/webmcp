<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

use Koh\WebMcp\Domain\Model\PageToolConfiguration;

/**
 * The per-page tool overrides an editor sets in page TSconfig
 * (`webmcp.tools.<name>.enabled = 0`).
 *
 * Presentation reads them when it advertises tools on a page, and binds them
 * to the nonce it mints there, so that the API middleware — which runs before
 * any page is resolved — applies the same overrides when the tool is called.
 */
interface PageToolConfigurationProviderInterface
{
    public function getConfigurationForPage(int $pageId): PageToolConfiguration;
}
