<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Infrastructure\Http\A2aAgentCard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class A2aAgentCardTest extends TestCase
{
    #[Test]
    public function theCardIsServedOnlyByAnA2aCapableBusinessAgentOnItsSites(): void
    {
        $withSet = self::site('https://example.com/', ['businessAgent' => ['agent' => ['name' => 'A']]]);

        $this->activate(['business_agent']);
        self::assertTrue(new A2aAgentCard(\stdClass::class)->isServedFor($withSet));
        self::assertTrue(new A2aAgentCard(\stdClass::class)->isServedFor(self::site('https://example.com/', ['businessAgent' => []])));
        self::assertFalse(new A2aAgentCard(\stdClass::class)->isServedFor(self::site('https://example.com/', [])));
        self::assertFalse(new A2aAgentCard(\stdClass::class)->isServedFor(self::site('https://example.com/', ['businessAgent' => 'on'])));
        self::assertFalse(new A2aAgentCard()->isServedFor($withSet));

        $this->activate([]);
        self::assertFalse(new A2aAgentCard(\stdClass::class)->isServedFor($withSet));
    }

    #[Test]
    public function theCardIsAtTheWellKnownPathOfTheHost(): void
    {
        self::assertSame('https://example.com/.well-known/agent-card.json', new A2aAgentCard()->url(self::site('https://example.com/', [])));
        self::assertSame('https://example.com/.well-known/agent-card.json', new A2aAgentCard()->url(self::site('https://example.com/de/?x=1', [])));
    }

    /**
     * @param list<string> $keys
     */
    private function activate(array $keys): void
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturnCallback(static fn(string $key): bool => in_array($key, $keys, true));
        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function site(string $base, array $settings): Site
    {
        return new Site('demo', 1, ['base' => $base, 'settings' => $settings, 'languages' => []]);
    }
}
