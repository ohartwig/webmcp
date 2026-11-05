<?php

declare(strict_types=1);

namespace Koh\WebMcp\Presentation\EventListener;

use Koh\WebMcp\Domain\Model\DiscoveryPath;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Points the page head at the ARD manifest.
 *
 * ARD v0.91 §5.1 lists the HTML link tag as a discovery mechanism of its own,
 * next to the Link header the middleware sends: a crawler that reads the
 * document and never sees the headers (a cache, a saved page, a parser fed
 * the HTML alone) finds the manifest only this way.
 *
 * Two elements, one target. `rel="ard"` is what a conformant consumer MUST
 * honour; `rel="ai-catalog"` is the predecessor relation, which consumers MAY
 * still consult and which several scanners look for exclusively. Both name
 * `ard.json`, the path ARD defines, so neither leads to the predecessor path.
 *
 * Hooked to the asset rendering event because it fires on every frontend
 * render, cached pages included, before the header data is written into the
 * head (see InjectWebMcpJavaScriptListener). It is not gated on the WebMCP
 * switch: the manifest is served for every site, and so is its Link header.
 */
#[AsEventListener(identifier: 'webmcp/agent-discovery-links')]
final readonly class AddAgentDiscoveryLinksListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
    ) {}

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        // The event fires four times per render (inline or not, priority or
        // not); one of them is enough.
        if (!$event->isInline() || !$event->isPriority()) {
            return;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface || !ApplicationType::fromRequest($request)->isFrontend()) {
            return;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return;
        }

        $href = htmlspecialchars(rtrim((string) $site->getBase(), '/') . DiscoveryPath::ARD);

        $this->pageRenderer->addHeaderData('<link rel="ard" href="' . $href . '">');
        $this->pageRenderer->addHeaderData('<link rel="ai-catalog" href="' . $href . '">');
    }
}
