<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Configuration;

use Koh\WebMcp\Infrastructure\Configuration\SiteConfigurationProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SiteConfigurationProviderTest extends TestCase
{
    #[Test]
    public function returnsDefaultsForAnEmptyIdentifier(): void
    {
        // A request that never resolved a site is a normal condition (early
        // middleware, CLI), so it yields defaults rather than an error.
        $settings = $this->settingsForUnknownSite('');

        self::assertFalse($settings['enabled']);
        self::assertSame('/_webmcp/api', $settings['apiBasePath']);
    }

    #[Test]
    public function returnsDefaultsForAnUnknownIdentifier(): void
    {
        $settings = $this->settingsForUnknownSite('does-not-exist');

        self::assertFalse($settings['enabled']);
        self::assertSame('/_webmcp/api', $settings['apiBasePath']);
    }

    #[Test]
    public function defaultsHaveCorrectToolKeys(): void
    {
        $settings = $this->settingsForUnknownSite('');

        self::assertArrayHasKey('search', $settings['tools']);
        self::assertArrayHasKey('navigation', $settings['tools']);
        self::assertArrayHasKey('page-content', $settings['tools']);
    }

    #[Test]
    public function defaultsHaveBridgeSettings(): void
    {
        $settings = $this->settingsForUnknownSite('');

        self::assertArrayHasKey('bridge', $settings);
        self::assertFalse($settings['bridge']['enabled']);
        self::assertSame('#007bff', $settings['bridge']['widget']['color']);
        self::assertSame('bottom-right', $settings['bridge']['widget']['position']);
    }

    #[Test]
    public function readsBridgeSettingsFromSite(): void
    {
        $settings = $this->settingsForSite([
            'webmcp' => [
                'enabled' => true,
                'bridge' => [
                    'enabled' => true,
                    'widget' => [
                        'color' => '#ff0000',
                        'position' => 'top-left',
                        'size' => '40px',
                        'padding' => '10px',
                    ],
                ],
            ],
        ]);

        self::assertTrue($settings['bridge']['enabled']);
        self::assertSame('#ff0000', $settings['bridge']['widget']['color']);
        self::assertSame('top-left', $settings['bridge']['widget']['position']);
        self::assertSame('40px', $settings['bridge']['widget']['size']);
        self::assertSame('10px', $settings['bridge']['widget']['padding']);
        self::assertSame('always', $settings['bridge']['widget']['visibility']);
    }

    #[Test]
    public function readsWidgetOptInVisibilityFromSite(): void
    {
        $settings = $this->settingsForSite([
            'webmcp' => [
                // webmcp.enabled decides whether the provider reads the site at
                // all — without it everything falls back to the defaults.
                'enabled' => true,
                'bridge' => [
                    'enabled' => true,
                    'widget' => ['visibility' => 'optin'],
                ],
            ],
        ]);

        self::assertSame('optin', $settings['bridge']['widget']['visibility']);
    }

    #[Test]
    public function fallsBackToAlwaysForAnUnknownVisibility(): void
    {
        // A typo must not hide the widget silently — anything that is not the
        // one opt-in value keeps the documented default behaviour.
        $settings = $this->settingsForSite([
            'webmcp' => [
                'enabled' => true,
                'bridge' => [
                    'enabled' => true,
                    'widget' => ['visibility' => 'hidden'],
                ],
            ],
        ]);

        self::assertSame('always', $settings['bridge']['widget']['visibility']);
    }

    #[Test]
    public function returnsDefaultsWhenWebMcpIsNotConfigured(): void
    {
        $settings = $this->settingsForSite(['other' => ['thing' => 1]]);

        self::assertFalse($settings['enabled']);
        self::assertSame('/_webmcp/api', $settings['apiBasePath']);
    }

    #[Test]
    public function fallsBackToDefaultsForUnconfiguredKeys(): void
    {
        // Only `enabled` is set — every other key must fall back rather than
        // becoming null (TYPO3 v14 returns the default instead of throwing).
        $settings = $this->settingsForSite(['webmcp' => ['enabled' => true]]);

        self::assertTrue($settings['enabled']);
        self::assertSame('/_webmcp/api', $settings['apiBasePath']);
        self::assertSame('', $settings['originTrialToken']);
        self::assertFalse($settings['debug']);
        self::assertSame('native', $settings['consent']['provider']);
        self::assertTrue($settings['tools']['search']['enabled']);
        self::assertSame(20, $settings['tools']['search']['maxResults']);
        self::assertSame(4, $settings['tools']['navigation']['maxDepth']);
        self::assertFalse($settings['tools']['form']['enabled']);
        self::assertSame('functional', $settings['tools']['search']['consent']['category']);
        self::assertSame('#007bff', $settings['bridge']['widget']['color']);
    }

    #[Test]
    public function readsOriginTrialTokenAndDebugFlag(): void
    {
        $settings = $this->settingsForSite([
            'webmcp' => [
                'enabled' => true,
                'originTrialToken' => 'A-TOKEN',
                'debug' => true,
            ],
        ]);

        self::assertSame('A-TOKEN', $settings['originTrialToken']);
        self::assertTrue($settings['debug']);
    }

    /**
     * @param array<string, mixed> $settingsTree
     *
     * @return array<string, mixed>
     */
    private function settingsForSite(array $settingsTree): array
    {
        // SiteSettings is final — build a real one instead of doubling it.
        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn(SiteSettings::createFromSettingsTree($settingsTree));

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($site);

        return new SiteConfigurationProvider($siteFinder)->getSettings('demo');
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsForUnknownSite(string $identifier): array
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willThrowException(new SiteNotFoundException());

        return new SiteConfigurationProvider($siteFinder)->getSettings($identifier);
    }
}
