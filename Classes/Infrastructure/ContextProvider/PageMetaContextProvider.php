<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ContextProvider;

use Koh\WebMcp\Domain\Contract\ContextProviderInterface;
use Koh\WebMcp\Domain\Model\ContextDefinition;

final readonly class PageMetaContextProvider implements ContextProviderInterface
{
    public function getIdentifier(): string
    {
        return 'page-meta';
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

        $pageInformation = $request->getAttribute('frontend.page.information');
        if (null === $pageInformation) {
            return [];
        }

        $pageRecord = $pageInformation->getPageRecord();

        $data = [
            'title' => $pageRecord['title'] ?? '',
            'subtitle' => $pageRecord['subtitle'] ?? '',
            'description' => $pageRecord['description'] ?? '',
            'abstract' => $pageRecord['abstract'] ?? '',
            'keywords' => $pageRecord['keywords'] ?? '',
            'slug' => $pageRecord['slug'] ?? '',
            'pageId' => $pageRecord['uid'] ?? 0,
        ];

        // Filter empty values
        $data = array_filter($data, static fn($value) => '' !== $value && 0 !== $value);

        if ([] === $data) {
            return [];
        }

        return [
            new ContextDefinition(
                name: 'page-meta',
                description: 'Metadata of the current page including title, description, and keywords',
                data: $data,
            ),
        ];
    }
}
