<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Presentation\EventListener;

use Koh\WebMcp\Presentation\EventListener\AddAgentDiscoveryLinksListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

final class AddAgentDiscoveryLinksListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function addsArdAndPredecessorLinkElementsToTheHead(): void
    {
        $this->frontendRequest('https://example.com/');

        $added = [];
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::exactly(2))->method('addHeaderData')
            ->willReturnCallback(static function (string $data) use (&$added): void {
                $added[] = $data;
            });

        (new AddAgentDiscoveryLinksListener($pageRenderer))($this->event(inline: true, priority: true));

        self::assertSame([
            '<link rel="ard" href="https://example.com/.well-known/ard.json">',
            '<link rel="ai-catalog" href="https://example.com/.well-known/ard.json">',
        ], $added);
    }

    #[Test]
    public function escapesTheSiteBase(): void
    {
        $this->frontendRequest('https://example.com/a&b');

        $added = [];
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::exactly(2))->method('addHeaderData')
            ->willReturnCallback(static function (string $data) use (&$added): void {
                $added[] = $data;
            });

        (new AddAgentDiscoveryLinksListener($pageRenderer))($this->event(inline: true, priority: true));

        self::assertSame('<link rel="ard" href="https://example.com/a&amp;b/.well-known/ard.json">', $added[0]);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function otherRenderPasses(): iterable
    {
        yield 'inline, not priority' => [true, false];
        yield 'file, priority' => [false, true];
        yield 'file, not priority' => [false, false];
    }

    #[Test]
    #[DataProvider('otherRenderPasses')]
    public function actsOnOneRenderPassOnly(bool $inline, bool $priority): void
    {
        $this->frontendRequest('https://example.com/');

        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::never())->method('addHeaderData');

        (new AddAgentDiscoveryLinksListener($pageRenderer))($this->event(inline: $inline, priority: $priority));
    }

    #[Test]
    public function leavesBackendPagesAlone(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', $this->site('https://example.com/'));

        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::never())->method('addHeaderData');

        (new AddAgentDiscoveryLinksListener($pageRenderer))($this->event(inline: true, priority: true));
    }

    #[Test]
    public function needsASite(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);

        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::never())->method('addHeaderData');

        (new AddAgentDiscoveryLinksListener($pageRenderer))($this->event(inline: true, priority: true));
    }

    #[Test]
    public function needsARequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::never())->method('addHeaderData');

        (new AddAgentDiscoveryLinksListener($pageRenderer))($this->event(inline: true, priority: true));
    }

    private function frontendRequest(string $base): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $this->site($base));
    }

    private function site(string $base): Site
    {
        return new Site('demo', 1, ['base' => $base, 'languages' => []]);
    }

    private function event(bool $inline, bool $priority): BeforeJavaScriptsRenderingEvent
    {
        return new BeforeJavaScriptsRenderingEvent($this->createStub(AssetCollector::class), $inline, $priority);
    }
}
