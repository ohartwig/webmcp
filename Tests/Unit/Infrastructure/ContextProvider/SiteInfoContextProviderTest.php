<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ContextProvider;

use Koh\WebMcp\Infrastructure\ContextProvider\SiteInfoContextProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The site context tells an agent which site it is on and which languages
 * exist. The type guard matters: the site attribute is a NullSite in the
 * backend and on CLI, and passing that through would advertise a site that
 * has no base and no languages.
 */
final class SiteInfoContextProviderTest extends TestCase
{
    private SiteInfoContextProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new SiteInfoContextProvider();
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        parent::tearDown();
    }

    private function requestWithSite(mixed $site): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('site')->willReturn($site);

        return $request;
    }

    private function language(int $id, string $title, string $locale, string $navigationTitle): SiteLanguage&\PHPUnit\Framework\MockObject\MockObject
    {
        $localeObject = $this->createMock(\TYPO3\CMS\Core\Localization\Locale::class);
        $localeObject->method('posixFormatted')->willReturn($locale);

        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn($id);
        $language->method('getTitle')->willReturn($title);
        $language->method('getLocale')->willReturn($localeObject);
        $language->method('getNavigationTitle')->willReturn($navigationTitle);

        return $language;
    }

    #[Test]
    public function identifierIsStable(): void
    {
        self::assertSame('site-info', $this->subject->getIdentifier());
    }

    #[Test]
    public function siteAndItsLanguagesAreExposedAsContext(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getBase')->willReturn(new \GuzzleHttp\Psr7\Uri('https://example.org/'));
        $site->method('getLanguages')->willReturn([
            $this->language(0, 'Deutsch', 'de_DE', 'DE'),
            $this->language(1, 'English', 'en_US', 'EN'),
        ]);

        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithSite($site);

        $contexts = $this->subject->getContexts();

        self::assertCount(1, $contexts);
        self::assertSame('site-info', $contexts[0]->name);
        self::assertSame('main', $contexts[0]->data['identifier']);
        self::assertSame('https://example.org/', $contexts[0]->data['base']);
        self::assertSame([
            ['id' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE', 'navigationTitle' => 'DE'],
            ['id' => 1, 'title' => 'English', 'locale' => 'en_US', 'navigationTitle' => 'EN'],
        ], $contexts[0]->data['languages']);
    }

    /**
     * A site with no configured languages is still a site — it just reports an
     * empty list rather than dropping the context.
     */
    #[Test]
    public function aSiteWithoutLanguagesStillYieldsContext(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getBase')->willReturn(new \GuzzleHttp\Psr7\Uri('https://example.org/'));
        $site->method('getLanguages')->willReturn([]);

        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithSite($site);

        self::assertSame([], $this->subject->getContexts()[0]->data['languages']);
    }

    #[Test]
    public function noRequestYieldsNoContext(): void
    {
        self::assertSame([], $this->subject->getContexts());
    }

    /**
     * Outside the frontend the site attribute is not a Site at all. Anything
     * that is not one has to be rejected rather than duck-typed.
     */
    #[Test]
    public function anAttributeThatIsNotASiteYieldsNoContext(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithSite(null);

        self::assertSame([], $this->subject->getContexts());
    }
}
