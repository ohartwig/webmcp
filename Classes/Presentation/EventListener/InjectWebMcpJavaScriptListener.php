<?php

declare(strict_types=1);

namespace Koh\WebMcp\Presentation\EventListener;

use Koh\WebMcp\Application\Contract\ClientFingerprintInterface;
use Koh\WebMcp\Application\Contract\ConsentCheckInterface;
use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use Koh\WebMcp\Application\Service\ContextRegistry;
use Koh\WebMcp\Application\Service\ToolRegistry;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

#[AsEventListener(identifier: 'webmcp/inject-javascript')]
final readonly class InjectWebMcpJavaScriptListener
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private ContextRegistry $contextRegistry,
        private SiteConfigurationProviderInterface $siteConfigurationProvider,
        private ConsentCheckInterface $consentCheck,
        private ClientFingerprintInterface $clientFingerprint,
        private AssetCollector $assetCollector,
        private PageRenderer $pageRenderer,
        private FrontendInterface $cache,
        private Random $random,
    ) {}

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        if (!$event->isInline()) {
            return;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (null === $request) {
            return;
        }

        $site = $request->getAttribute('site');
        $settings = $this->siteConfigurationProvider->getSettings(
            $site instanceof Site ? $site->getIdentifier() : '',
        );
        if (!((bool) ($settings['enabled'] ?? false))) {
            return;
        }

        $tools = $this->toolRegistry->getTools($settings);
        $contexts = $this->contextRegistry->getContexts($settings);

        if ([] === $tools && [] === $contexts) {
            return;
        }

        $apiBasePath = $settings['apiBasePath'] ?? '/_webmcp/api';
        $nonce = $this->random->generateRandomHexString(32);

        // Must match what the API middleware computes, or no nonce validates.
        $fingerprint = $this->clientFingerprint->ofCurrentRequest();

        // Store nonce with fingerprint in cache (TTL: 1 hour)
        $this->cache->set('nonce_' . $nonce, json_encode([
            'nonce' => $nonce,
            'fingerprint' => $fingerprint,
            'created' => time(),
        ]), ['webmcp_nonce'], 3600);

        // Build consent requirements for tools that need consent
        $consentRequirements = [];
        foreach ($tools as $tool) {
            $requirement = $this->consentCheck->getConsentRequirement($tool->name, $settings);
            if ($requirement->required) {
                $consentRequirements[$tool->name] = $requirement;
            }
        }

        $consentProvider = $settings['consent']['provider'] ?? 'native';
        $bridgeEnabled = (bool) ($settings['bridge']['enabled'] ?? false);

        // Build JSON config — no inline JavaScript, CSP-safe
        $config = [
            'api' => $apiBasePath,
            'nonce' => $nonce,
            'tools' => array_map(static fn($t) => $t->toArray(), $tools),
            'contexts' => array_map(static fn($c) => $c->toArray(), $contexts),
            'consent' => [
                'provider' => $consentProvider,
                'requirements' => array_map(
                    static fn($r) => ['required' => $r->required, 'category' => $r->category],
                    $consentRequirements,
                ),
            ],
            'bridge' => [
                'enabled' => $bridgeEnabled,
                'widget' => $bridgeEnabled ? ($settings['bridge']['widget'] ?? []) : [],
            ],
            'debug' => (bool) ($settings['debug'] ?? false),
        ];

        $configJson = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        // Chrome 149-156 gates the WebMCP API behind an Origin Trial; without a
        // token document.modelContext is simply undefined. This event fires from
        // PageRenderer::renderJavaScriptAndCss(), which runs before the meta tags
        // are rendered, so setting it here still lands in the <head>.
        $originTrialToken = trim((string) ($settings['originTrialToken'] ?? ''));
        if ('' !== $originTrialToken) {
            $this->pageRenderer->setMetaTag('http-equiv', 'origin-trial', $originTrialToken);
        }

        // Load bridge widget JS when enabled (external, before init). Defer-Attribut
        // damit Browser-Parser nicht blockt — order zwischen bridge + init bleibt
        // erhalten, weil defer execution-order garantiert.
        if ($bridgeEnabled) {
            $this->assetCollector->addJavaScript(
                'webmcp-bridge-script',
                'EXT:webmcp/Resources/Public/JavaScript/webmcp.js',
                ['defer' => 'defer'],
                ['priority' => false],
            );
        }

        // Single external init script with JSON config as data attribute — zero inline JS
        $this->assetCollector->addJavaScript(
            'webmcp-init',
            'EXT:webmcp/Resources/Public/JavaScript/webmcp-init.js',
            ['id' => 'webmcp-init', 'data-webmcp-config' => $configJson, 'defer' => 'defer'],
            ['priority' => false],
        );
    }
}
