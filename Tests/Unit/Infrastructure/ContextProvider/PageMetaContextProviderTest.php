<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ContextProvider;

use Koh\WebMcp\Infrastructure\ContextProvider\PageMetaContextProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Whatever this provider returns is handed to an agent as page context, so the
 * interesting part is not the happy path but the absences: no request, no page
 * information, or a page whose metadata fields are simply empty. Each of those
 * has to yield no context at all rather than a hull of empty strings that an
 * agent would then reason about.
 */
final class PageMetaContextProviderTest extends TestCase
{
    private PageMetaContextProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new PageMetaContextProvider();
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $pageRecord
     */
    private function requestWithPageRecord(array $pageRecord): ServerRequestInterface
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord($pageRecord);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('frontend.page.information')
            ->willReturn($pageInformation);

        return $request;
    }

    #[Test]
    public function identifierIsStable(): void
    {
        self::assertSame('page-meta', $this->subject->getIdentifier());
    }

    #[Test]
    public function pageMetadataIsExposedAsContext(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithPageRecord([
            'uid' => 42,
            'title' => 'About us',
            'subtitle' => 'The team',
            'description' => 'Who we are',
            'abstract' => 'Short version',
            'keywords' => 'team, company',
            'slug' => '/about-us',
        ]);

        $contexts = $this->subject->getContexts();

        self::assertCount(1, $contexts);
        self::assertSame('page-meta', $contexts[0]->name);
        self::assertSame([
            'title' => 'About us',
            'subtitle' => 'The team',
            'description' => 'Who we are',
            'abstract' => 'Short version',
            'keywords' => 'team, company',
            'slug' => '/about-us',
            'pageId' => 42,
        ], $contexts[0]->data);
    }

    /**
     * Empty metadata fields are dropped, so an agent is not handed a row of
     * blank values it might otherwise treat as meaningful.
     */
    #[Test]
    public function emptyFieldsAreOmittedFromTheContext(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithPageRecord([
            'uid' => 42,
            'title' => 'About us',
            'subtitle' => '',
            'description' => '',
            'abstract' => '',
            'keywords' => '',
            'slug' => '/about-us',
        ]);

        $data = $this->subject->getContexts()[0]->data;

        self::assertSame(['title' => 'About us', 'slug' => '/about-us', 'pageId' => 42], $data);
    }

    /**
     * A page record that carries nothing usable must not produce an empty
     * context object.
     */
    #[Test]
    public function aPageWithoutAnyMetadataYieldsNoContext(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithPageRecord(['uid' => 0]);

        self::assertSame([], $this->subject->getContexts());
    }

    /**
     * The provider is also reachable outside a frontend request — in the
     * backend or on CLI — where there is no request at all.
     */
    #[Test]
    public function noRequestYieldsNoContext(): void
    {
        self::assertSame([], $this->subject->getContexts());
    }

    #[Test]
    public function aRequestWithoutPageInformationYieldsNoContext(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        self::assertSame([], $this->subject->getContexts());
    }
}
