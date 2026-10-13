<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Configuration;

use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class SiteConfigurationProvider implements SiteConfigurationProviderInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * Sentinel for "setting not configured". TYPO3 v13 signals this by throwing
     * from SiteSettings::get(), v14 by returning the passed default — reading
     * through readSetting() covers both.
     */
    private const string MISSING = "\0webmcp-missing\0";

    /**
     * Get WebMCP settings for one site.
     *
     * @return array<string, mixed>
     */
    public function getSettings(string $siteIdentifier): array
    {
        if ('' === $siteIdentifier) {
            return $this->getDefaults();
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException) {
            return $this->getDefaults();
        }

        $siteSettings = $site->getSettings();
        $webmcpSettings = [];

        $enabled = $this->readSetting($siteSettings, 'webmcp.enabled', self::MISSING);
        if (self::MISSING === $enabled) {
            return $this->getDefaults();
        }
        $webmcpSettings['enabled'] = $enabled;

        $webmcpSettings['apiBasePath'] = $this->readSetting($siteSettings, 'webmcp.apiBasePath', '/_webmcp/api');
        $webmcpSettings['originTrialToken'] = (string) $this->readSetting($siteSettings, 'webmcp.originTrialToken', '');
        $webmcpSettings['debug'] = (bool) $this->readSetting($siteSettings, 'webmcp.debug', false);
        $webmcpSettings['consent']['provider'] = $this->readSetting($siteSettings, 'webmcp.consent.provider', 'native');

        $webmcpSettings['tools'] = [];
        $toolMap = [
            'search' => 'search',
            'navigation' => 'navigation',
            'page-content' => 'page-content',
            'form' => 'form',
        ];

        foreach ($toolMap as $toolName => $settingsKey) {
            $prefix = 'webmcp.tools.' . $settingsKey;

            $webmcpSettings['tools'][$toolName] = [
                'enabled' => $this->readSetting($siteSettings, $prefix . '.enabled', 'form' !== $toolName),
            ];

            // Tool-specific settings
            if ('search' === $toolName) {
                $webmcpSettings['tools'][$toolName]['maxResults'] = $this->readSetting($siteSettings, $prefix . '.maxResults', 20);
            }
            if ('navigation' === $toolName) {
                $webmcpSettings['tools'][$toolName]['maxDepth'] = $this->readSetting($siteSettings, $prefix . '.maxDepth', 4);
            }
            if ('form' === $toolName) {
                $webmcpSettings['tools'][$toolName]['allowedForms'] = $this->readSetting($siteSettings, $prefix . '.allowedForms', '');
            }

            $webmcpSettings['tools'][$toolName]['consent'] = [
                'required' => $this->readSetting($siteSettings, $prefix . '.consent.required', false),
                'category' => $this->readSetting($siteSettings, $prefix . '.consent.category', 'functional'),
            ];
        }

        // Bridge settings
        $webmcpSettings['bridge'] = $this->readBridgeSettings($siteSettings);

        return $webmcpSettings;
    }

    /**
     * @return array{enabled: bool, widget: array{visibility: string, color: string, position: string, size: string, padding: string}}
     */
    private function readBridgeSettings(SiteSettings $siteSettings): array
    {
        return [
            'enabled' => (bool) $this->readSetting($siteSettings, 'webmcp.bridge.enabled', false),
            'widget' => [
                // "always" or "optin" — the bridge loads either way, this only
                // decides whether visitors see the trigger. Anything else falls
                // back to "always" so a typo cannot silently hide the widget.
                'visibility' => $this->readWidgetVisibility($siteSettings),
                'color' => (string) $this->readSetting($siteSettings, 'webmcp.bridge.widget.color', '#007bff'),
                'position' => (string) $this->readSetting($siteSettings, 'webmcp.bridge.widget.position', 'bottom-right'),
                'size' => (string) $this->readSetting($siteSettings, 'webmcp.bridge.widget.size', '30px'),
                'padding' => (string) $this->readSetting($siteSettings, 'webmcp.bridge.widget.padding', '20px'),
            ],
        ];
    }

    private function readWidgetVisibility(SiteSettings $siteSettings): string
    {
        $visibility = (string) $this->readSetting($siteSettings, 'webmcp.bridge.widget.visibility', 'always');

        return 'optin' === $visibility ? 'optin' : 'always';
    }

    /**
     * Read one site setting, falling back to $default when it is not configured.
     *
     * TYPO3 v13 throws for unknown identifiers, v14 returns the default we pass
     * in — and it also returns null for a configured-but-null value, which must
     * fall back too. All three cases land on $default here.
     */
    private function readSetting(SiteSettings $siteSettings, string $identifier, mixed $default): mixed
    {
        try {
            $value = $siteSettings->get($identifier, self::MISSING);
        } catch (\InvalidArgumentException) {
            return $default;
        }

        return (self::MISSING === $value || null === $value) ? $default : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaults(): array
    {
        return [
            'enabled' => false,
            'apiBasePath' => '/_webmcp/api',
            'originTrialToken' => '',
            'debug' => false,
            'consent' => ['provider' => 'native'],
            'bridge' => [
                'enabled' => false,
                'widget' => [
                    'visibility' => 'always',
                    'color' => '#007bff',
                    'position' => 'bottom-right',
                    'size' => '30px',
                    'padding' => '20px',
                ],
            ],
            'tools' => [
                'search' => ['enabled' => true, 'maxResults' => 20, 'consent' => ['required' => false, 'category' => 'functional']],
                'navigation' => ['enabled' => true, 'maxDepth' => 4, 'consent' => ['required' => false, 'category' => 'functional']],
                'page-content' => ['enabled' => true, 'consent' => ['required' => false, 'category' => 'functional']],
                'form' => ['enabled' => false, 'allowedForms' => '', 'consent' => ['required' => false, 'category' => 'functional']],
            ],
        ];
    }
}
