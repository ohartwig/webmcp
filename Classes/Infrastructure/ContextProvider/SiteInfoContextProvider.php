<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ContextProvider;

use Koh\WebMcp\Domain\Contract\ContextProviderInterface;
use Koh\WebMcp\Domain\Model\ContextDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class SiteInfoContextProvider implements ContextProviderInterface
{
    public function getIdentifier(): string
    {
        return 'site-info';
    }

    /**
     * @return ContextDefinition[]
     */
    public function getContexts(): array
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (null === $request) {
            return [];
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return [];
        }

        $languages = [];
        foreach ($site->getLanguages() as $language) {
            $languages[] = [
                'id' => $language->getLanguageId(),
                'title' => $language->getTitle(),
                'locale' => $language->getLocale()->posixFormatted(),
                'navigationTitle' => $language->getNavigationTitle(),
            ];
        }

        return [
            new ContextDefinition(
                name: 'site-info',
                description: 'Information about the current website including name, base URL, and available languages',
                data: [
                    'identifier' => $site->getIdentifier(),
                    'base' => (string) $site->getBase(),
                    'languages' => $languages,
                ],
            ),
        ];
    }
}
