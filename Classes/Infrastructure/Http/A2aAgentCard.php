<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Whether a site serves an A2A Agent Card at `/.well-known/agent-card.json`.
 *
 * The card belongs to `koh/business-agent`, and it is served only under
 * three conditions, all checked here so the catalogue lists it exactly then:
 *
 *  - the extension is loaded;
 *  - it is a version with an A2A endpoint. Up to 1.2.6 the card named the
 *    MCP endpoint as an A2A interface, 1.2.7 and 1.2.8 serve no card at all.
 *    The card factory exists only in the versions that serve a valid card
 *    with an endpoint behind it, so its presence is the test;
 *  - the site applies the Business Agent site set (its `businessAgent`
 *    settings), which is how business-agent itself decides per site.
 */
final readonly class A2aAgentCard
{
    public const string PATH = '/.well-known/agent-card.json';

    /** The business-agent class that builds the card; it came with the A2A endpoint. */
    private const string CARD_FACTORY = 'Koh\BusinessAgent\Infrastructure\A2a\AgentCardFactory';

    public function __construct(
        private string $cardFactoryClass = self::CARD_FACTORY,
    ) {}

    public function isServedFor(Site $site): bool
    {
        if (!ExtensionManagementUtility::isLoaded('business_agent') || !class_exists($this->cardFactoryClass)) {
            return false;
        }

        $settings = $site->getSettings()->getAll();

        return isset($settings['businessAgent']) && \is_array($settings['businessAgent']);
    }

    /**
     * Where the card is: the well-known path on the site's host, whatever
     * path the site's base carries.
     */
    public function url(Site $site): string
    {
        return (string) $site->getBase()->withPath(self::PATH)->withQuery('')->withFragment('');
    }
}
