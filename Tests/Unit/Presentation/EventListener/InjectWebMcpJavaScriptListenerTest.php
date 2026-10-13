<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Presentation\EventListener;

use Koh\WebMcp\Application\Contract\ClientFingerprintInterface;
use Koh\WebMcp\Application\Contract\ConsentCheckInterface;
use Koh\WebMcp\Application\Service\ContextRegistry;
use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Contract\ContextProviderInterface;
use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ConsentRequirement;
use Koh\WebMcp\Domain\Model\ContextDefinition;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use Koh\WebMcp\Infrastructure\ToolProvider\ContextToolProvider;
use Koh\WebMcp\Presentation\EventListener\InjectWebMcpJavaScriptListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Page\PageRenderer;

final class InjectWebMcpJavaScriptListenerTest extends TestCase
{
    private InjectWebMcpJavaScriptListener $listener;
    private SiteConfigurationProviderInterface $siteConfigurationProvider;
    private ClientFingerprintInterface $clientFingerprint;
    private AssetCollector $assetCollector;
    private PageRenderer $pageRenderer;
    private FrontendInterface $cache;
    private Random $random;

    protected function setUp(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        $consentCheck = $this->createMock(ConsentCheckInterface::class);
        $consentCheck->method('isToolAllowed')->willReturn(true);
        $consentCheck->method('getConsentRequirement')
            ->willReturn(new ConsentRequirement(toolName: 'test'));

        $toolProvider = $this->createMock(ToolProviderInterface::class);
        $toolProvider->method('getTools')->willReturn([
            new ToolDefinition(name: 'search', description: 'Search', inputSchema: []),
        ]);

        $contextProvider = $this->createMock(ContextProviderInterface::class);
        $contextProvider->method('getContexts')->willReturn([
            new ContextDefinition(name: 'page-meta', description: 'Meta', data: ['title' => 'Home']),
        ]);

        $contextRegistry = new ContextRegistry([$contextProvider], $dispatcher);

        // Mirrors the DI wiring: context tools reach the payload through the
        // regular ToolRegistry, not through a special case in the listener.
        $toolRegistry = new ToolRegistry(
            [$toolProvider, new ContextToolProvider($contextRegistry)],
            $dispatcher,
        );

        $this->siteConfigurationProvider = $this->createMock(SiteConfigurationProviderInterface::class);
        $this->clientFingerprint = $this->createMock(ClientFingerprintInterface::class);
        $this->assetCollector = $this->createMock(AssetCollector::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->random = $this->createMock(Random::class);

        $this->listener = new InjectWebMcpJavaScriptListener(
            $toolRegistry,
            $contextRegistry,
            $this->siteConfigurationProvider,
            $consentCheck,
            $this->clientFingerprint,
            $this->assetCollector,
            $this->pageRenderer,
            $this->cache,
            $this->random,
        );
    }

    #[Test]
    public function skipsWhenNotInline(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: false, priority: false);

        $this->assetCollector->expects(self::never())->method('addJavaScript');

        ($this->listener)($event);
    }

    #[Test]
    public function skipsWhenNoRequest(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        unset($GLOBALS['TYPO3_REQUEST']);

        $this->assetCollector->expects(self::never())->method('addJavaScript');

        ($this->listener)($event);
    }

    #[Test]
    public function skipsWhenDisabled(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => false]);

        $this->assetCollector->expects(self::never())->method('addJavaScript');

        ($this->listener)($event);

        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function injectsExternalInitScriptWhenEnabled(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('TestAgent');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->random->method('generateRandomHexString')->willReturn(str_repeat('b', 64));

        $this->cache->expects(self::once())->method('set');
        $this->assetCollector->expects(self::never())->method('addInlineJavaScript');
        $this->assetCollector->expects(self::once())
            ->method('addJavaScript')
            ->with(
                'webmcp-init',
                'EXT:webmcp/Resources/Public/JavaScript/webmcp-init.js',
                self::callback(function (array $attrs) {
                    return ($attrs['id'] ?? '') === 'webmcp-init'
                        && isset($attrs['data-webmcp-config'])
                        && null !== json_decode($attrs['data-webmcp-config'], true);
                }),
            );

        ($this->listener)($event);

        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function injectsBridgeAndInitScriptWhenBridgeEnabled(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('TestAgent');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn([
                'enabled' => true,
                'apiBasePath' => '/_webmcp/api',
                'bridge' => ['enabled' => true, 'widget' => ['color' => '#007bff', 'position' => 'bottom-right', 'size' => '30px', 'padding' => '20px']],
            ]);
        $this->random->method('generateRandomHexString')->willReturn(str_repeat('a', 64));

        $this->assetCollector->expects(self::never())->method('addInlineJavaScript');

        // Bridge external JS + init script with JSON config
        $this->assetCollector->expects(self::exactly(2))
            ->method('addJavaScript')
            ->with(
                self::logicalOr('webmcp-bridge-script', 'webmcp-init'),
                self::isString(),
            );

        ($this->listener)($event);

        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function doesNotInjectBridgeWhenBridgeDisabled(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('TestAgent');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn([
                'enabled' => true,
                'apiBasePath' => '/_webmcp/api',
                'bridge' => ['enabled' => false],
            ]);
        $this->random->method('generateRandomHexString')->willReturn(str_repeat('a', 64));

        $this->assetCollector->expects(self::never())->method('addInlineJavaScript');
        // Only init script, no bridge script
        $this->assetCollector->expects(self::once())
            ->method('addJavaScript')
            ->with('webmcp-init', 'EXT:webmcp/Resources/Public/JavaScript/webmcp-init.js', self::isArray());

        ($this->listener)($event);

        unset($GLOBALS['TYPO3_REQUEST']);
    }

    /**
     * The listener must store the port's answer untampered — how the
     * fingerprint is derived is the port's business (covered by its own test),
     * but a nonce bound to anything other than that exact value can never
     * validate in the API middleware.
     */
    #[Test]
    public function storesNonceWithFingerprintInCache(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $nonce = str_repeat('c', 64);
        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
        $this->random->method('generateRandomHexString')->willReturn($nonce);

        $expectedFingerprint = hash('sha256', '192.168.1.1|Mozilla/5.0');
        $this->clientFingerprint->method('ofCurrentRequest')->willReturn($expectedFingerprint);

        $this->cache->expects(self::once())
            ->method('set')
            ->with(
                'nonce_' . $nonce,
                self::callback(function (string $value) use ($nonce, $expectedFingerprint) {
                    $data = json_decode($value, true);

                    return $data['nonce'] === $nonce
                        && $data['fingerprint'] === $expectedFingerprint
                        && isset($data['created']);
                }),
                ['webmcp_nonce'],
                3600,
            );

        ($this->listener)($event);

        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function configPayloadContainsDerivedContextTools(): void
    {
        $config = $this->captureConfig(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $names = array_column($config['tools'], 'name');
        self::assertContains('search', $names);
        self::assertContains('get-page-meta', $names);

        $contextTool = $config['tools'][array_search('get-page-meta', $names, true)];
        self::assertTrue($contextTool['annotations']['readOnlyHint']);
        self::assertSame([], $contextTool['inputSchema']['properties']);

        // The raw contexts stay in the payload — the bridge widget still
        // exposes them via registerResource().
        self::assertSame('page-meta', $config['contexts'][0]['name']);
    }

    #[Test]
    public function rendersOriginTrialMetaTagWhenTokenIsSet(): void
    {
        $this->pageRenderer->expects(self::once())
            ->method('setMetaTag')
            ->with('http-equiv', 'origin-trial', 'A-TOKEN');

        $this->captureConfig([
            'enabled' => true,
            'apiBasePath' => '/_webmcp/api',
            'originTrialToken' => 'A-TOKEN',
        ]);
    }

    #[Test]
    public function doesNotRenderOriginTrialMetaTagWithoutToken(): void
    {
        $this->pageRenderer->expects(self::never())->method('setMetaTag');

        $this->captureConfig(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);
    }

    #[Test]
    public function passesDebugFlagIntoConfigPayload(): void
    {
        $config = $this->captureConfig([
            'enabled' => true,
            'apiBasePath' => '/_webmcp/api',
            'debug' => true,
        ]);

        self::assertTrue($config['debug']);
    }

    /**
     * Runs the listener and returns the decoded data-webmcp-config payload.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function captureConfig(array $settings): array
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('TestAgent');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->siteConfigurationProvider->method('getSettings')->willReturn($settings);
        $this->random->method('generateRandomHexString')->willReturn(str_repeat('d', 64));

        $captured = null;
        $this->assetCollector->method('addJavaScript')
            ->willReturnCallback(function (string $id, string $source, array $attrs = []) use (&$captured) {
                if ('webmcp-init' === $id) {
                    $captured = json_decode($attrs['data-webmcp-config'], true, 512, JSON_THROW_ON_ERROR);
                }

                return $this->assetCollector;
            });

        ($this->listener)($event);

        unset($GLOBALS['TYPO3_REQUEST']);

        self::assertIsArray($captured, 'Listener did not add the webmcp-init script');

        return $captured;
    }
}
