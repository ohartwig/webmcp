<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Configuration;

use Koh\WebMcp\Domain\Model\PageToolConfiguration;
use TYPO3\CMS\Backend\Utility\BackendUtility;

final readonly class PageTsConfigToolProvider
{
    /**
     * Get page-level tool configuration from PageTSconfig.
     *
     * TSconfig format:
     *   webmcp.tools.search.enabled = 0
     *   webmcp.tools.navigation.enabled = 1
     */
    public function getConfigurationForPage(int $pageId): PageToolConfiguration
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);
        $webmcpConfig = $tsConfig['webmcp.'] ?? [];
        $toolsConfig = $webmcpConfig['tools.'] ?? [];

        $overrides = [];
        foreach ($toolsConfig as $toolKey => $toolSettings) {
            $toolName = rtrim($toolKey, '.');
            if (is_array($toolSettings) && isset($toolSettings['enabled'])) {
                $overrides[$toolName] = (bool) (int) $toolSettings['enabled'];
            }
        }

        return new PageToolConfiguration(toolOverrides: $overrides);
    }
}
