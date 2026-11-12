<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Presentation\EventListener;

use Koh\WebMcp\Application\Contract\ConsentCheckInterface;
use Koh\WebMcp\Application\Contract\PageToolConfigurationProviderInterface;
use Koh\WebMcp\Application\Service\ContextRegistry;
use Koh\WebMcp\Application\Service\ToolRegistry;
use Koh\WebMcp\Domain\Contract\ContextProviderInterface;
use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ConsentRequirement;
use Koh\WebMcp\Domain\Model\ContextDefinition;
use Koh\WebMcp\Domain\Model\PageToolConfiguration;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Koh\WebMcp\Application\Contract\SiteConfigurationProviderInterface;
use Koh\WebMcp\Infrastructure\ToolProvider\ContextToolProvider;
use Koh\WebMcp\Presentation\EventListener\InjectWebMcpJavaScriptListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Frontend\Page\PageInformation;

final class InjectWebMcpJavaScriptListenerTest extends TestCase
{
    private InjectWebMcpJavaScriptListener $listener;
    private SiteConfigurationProviderInterface $siteConfigurationProvider;
    private AssetCollector $assetCollector;
    private PageRenderer $pageRenderer;
    private PageToolConfigurationProviderInterface $pageToolConfiguration;
    private ToolRegistry $toolRegistry;
    private ContextRegistry $contextRegistry;
    private ConsentCheckInterface $consentCheck;

    protected function setUp(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(fn($event) => $event);

        $consentCheck = $this->createMock(ConsentCheckInterface::class);
        $consentCheck->method('isToolAllowed')->willReturn(true);
        $consentCheck->method('getConsentRequirement')
            ->willReturn(new ConsentRequirement(toolName: 'test'));
        $this->consentCheck = $consentCheck;

        $toolProvider = $this->createMock(ToolProviderInterface::class);
        $toolProvider->method('getTools')->willReturn([
            new ToolDefinition(name: 'search', description: 'Search', inputSchema: []),
        ]);

        $contextProvider = $this->createMock(ContextProviderInterface::class);
        $contextProvider->method('getContexts')->willReturn([
            new ContextDefinition(name: 'page-meta', description: 'Meta', data: ['title' => 'Home']),
        ]);

        $contextRegistry = new ContextRegistry([$contextProvider], $dispatcher);
        $this->contextRegistry = $contextRegistry;

        // Mirrors the DI wiring: context tools reach the payload through the
        // regular ToolRegistry, not through a special case in the listener.
        $toolRegistry = new ToolRegistry(
            [$toolProvider, new ContextToolProvider($contextRegistry)],
            $dispatcher,
        );
        $this->toolRegistry = $toolRegistry;

        $this->siteConfigurationProvider = $this->createMock(SiteConfigurationProviderInterface::class);
        $this->assetCollector = $this->createMock(AssetCollector::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);
        $this->pageToolConfiguration = $this->createMock(PageToolConfigurationProviderInterface::class);
        $this->pageToolConfiguration->method('getConfigurationForPage')->willReturn(new PageToolConfiguration());

        $this->listener = new InjectWebMcpJavaScriptListener(
            $toolRegistry,
            $contextRegistry,
            $this->siteConfigurationProvider,
            $consentCheck,
            $this->assetCollector,
            $this->pageRenderer,
            $this->pageToolConfiguration,
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

        $this->assetCollector->expects(self::never())->method('addInlineJavaScript');
        // Only init script, no bridge script
        $this->assetCollector->expects(self::once())
            ->method('addJavaScript')
            ->with('webmcp-init', 'EXT:webmcp/Resources/Public/JavaScript/webmcp-init.js', self::isArray());

        ($this->listener)($event);

        unset($GLOBALS['TYPO3_REQUEST']);
    }

    /**
     * The payload is part of a page that the page cache and the edge serve
     * to every visitor. A nonce in it was bound to whoever rendered the page;
     * it carries the page id instead, and the init script fetches its own.
     */
    #[Test]
    public function thePayloadCarriesThePageIdAndNoNonce(): void
    {
        $pageInformation = new PageInformation();
        $pageInformation->setId(42);

        $config = $this->captureConfig(['enabled' => true, 'apiBasePath' => '/_webmcp/api'], $pageInformation);

        self::assertArrayNotHasKey('nonce', $config);
        self::assertSame(42, $config['pageId']);
        self::assertSame('/_webmcp/api', $config['api']);
    }

    /**
     * Two renders of one page for two visitors produce the same bytes, so
     * the page cache holds one entry and the edge serves it to both.
     */
    #[Test]
    public function thePayloadIsTheSameForEveryVisitor(): void
    {
        $first = $this->captureConfig(['enabled' => true, 'apiBasePath' => '/_webmcp/api'], null, '198.51.100.1', 'Agent/1');
        $second = $this->captureConfig(['enabled' => true, 'apiBasePath' => '/_webmcp/api'], null, '198.51.100.2', 'Agent/2');

        self::assertSame($first, $second);
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
    private function captureConfig(
        array $settings,
        ?PageInformation $pageInformation = null,
        string $address = '127.0.0.1',
        string $userAgent = 'TestAgent',
    ): array {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $address]);
        $request->method('getHeaderLine')->willReturn($userAgent);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name) => 'frontend.page.information' === $name ? $pageInformation : null,
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->siteConfigurationProvider->method('getSettings')->willReturn($settings);

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

    /**
     * An editor's `webmcp.tools.search.enabled = 0` on a page keeps the tool
     * out of what that page advertises. The API applies the same override
     * through the nonce endpoint, which reads it again for the page id in the
     * payload (covered by the middleware test).
     */
    #[Test]
    public function pageOverridesAreAppliedToTheAdvertisedTools(): void
    {
        $event = new BeforeJavaScriptsRenderingEvent($this->assetCollector, isInline: true, priority: false);

        $pageInformation = new PageInformation();
        $pageInformation->setId(42);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('TestAgent');
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name) => 'frontend.page.information' === $name ? $pageInformation : null,
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $pageToolConfiguration = $this->createMock(PageToolConfigurationProviderInterface::class);
        $pageToolConfiguration->expects(self::once())
            ->method('getConfigurationForPage')
            ->with(42)
            ->willReturn(new PageToolConfiguration(['search' => false]));

        $listener = new InjectWebMcpJavaScriptListener(
            $this->toolRegistry,
            $this->contextRegistry,
            $this->siteConfigurationProvider,
            $this->consentCheck,
            $this->assetCollector,
            $this->pageRenderer,
            $pageToolConfiguration,
        );

        $this->siteConfigurationProvider->method('getSettings')
            ->willReturn(['enabled' => true, 'apiBasePath' => '/_webmcp/api']);

        $captured = null;
        $this->assetCollector->method('addJavaScript')
            ->willReturnCallback(function (string $id, string $source, array $attrs = []) use (&$captured) {
                if ('webmcp-init' === $id) {
                    $captured = json_decode($attrs['data-webmcp-config'], true, 512, JSON_THROW_ON_ERROR);
                }

                return $this->assetCollector;
            });

        $listener($event);
        unset($GLOBALS['TYPO3_REQUEST']);

        self::assertIsArray($captured);
        self::assertNotContains('search', array_column($captured['tools'], 'name'), 'the page switched search off');
        self::assertSame(42, $captured['pageId']);
    }
}
