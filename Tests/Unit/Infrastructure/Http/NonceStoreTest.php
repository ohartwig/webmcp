<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Infrastructure\Http\NonceStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\Random;

final class NonceStoreTest extends TestCase
{
    /** @var array<string, string> */
    private array $entries = [];

    private FrontendInterface $cache;

    protected function setUp(): void
    {
        $this->entries = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('set')->willReturnCallback(
            function (string $key, string $value): void {
                $this->entries[$key] = $value;
            },
        );
        $cache->method('get')->willReturnCallback(
            fn(string $key) => $this->entries[$key] ?? false,
        );
        $this->cache = $cache;
    }

    /**
     * Minting and validating with a real `Random`: 1.8.3 minted 32
     * characters and validated 64, and a mocked `Random` kept the tests green.
     */
    #[Test]
    public function aMintedNonceValidatesForTheClientItWasIssuedTo(): void
    {
        $store = new NonceStore($this->cache, new Random());
        $request = $this->request('198.51.100.7', 'Agent/1');

        $nonce = $store->issue($request, 42, ['search' => false]);

        self::assertSame(NonceStore::LENGTH, strlen($nonce));
        self::assertTrue(NonceStore::isWellFormed($nonce));

        $data = $store->lookup($nonce, $request);
        self::assertIsArray($data);
        self::assertSame(42, $data['pageId']);
        self::assertSame(['search' => false], $data['toolOverrides']);
    }

    #[Test]
    public function aMintedNonceDoesNotValidateForAnotherClient(): void
    {
        $store = new NonceStore($this->cache, new Random());
        $nonce = $store->issue($this->request('198.51.100.7', 'Agent/1'), 42, []);

        self::assertNull($store->lookup($nonce, $this->request('198.51.100.8', 'Agent/1')));
        self::assertNull($store->lookup($nonce, $this->request('198.51.100.7', 'Agent/2')));
    }

    /**
     * What 1.8.3 put into every page: 32 hex characters. Refused on its
     * form, before the cache is asked, even when an entry for it exists.
     */
    #[Test]
    public function aThirtyTwoCharacterNonceIsRejected(): void
    {
        $request = $this->request('198.51.100.7', 'Agent/1');
        $short = str_repeat('a', 32);
        $this->entries['nonce_' . $short] = json_encode([
            'nonce' => $short,
            'fingerprint' => hash('sha256', '198.51.100.7|Agent/1'),
            'pageId' => 1,
            'toolOverrides' => [],
        ], JSON_THROW_ON_ERROR);

        self::assertFalse(NonceStore::isWellFormed($short));
        self::assertNull((new NonceStore($this->cache, new Random()))->lookup($short, $request));
    }

    #[Test]
    public function aNonceOfTheWrongAlphabetIsRejected(): void
    {
        self::assertFalse(NonceStore::isWellFormed(str_repeat('A', NonceStore::LENGTH)));
        self::assertFalse(NonceStore::isWellFormed(str_repeat('g', NonceStore::LENGTH)));
        self::assertFalse(NonceStore::isWellFormed(str_repeat('a', NonceStore::LENGTH + 1)));
        self::assertTrue(NonceStore::isWellFormed(str_repeat('a', NonceStore::LENGTH)));
    }

    #[Test]
    public function anUnknownNonceIsRejected(): void
    {
        $store = new NonceStore($this->cache, new Random());

        self::assertNull($store->lookup(str_repeat('a', NonceStore::LENGTH), $this->request('198.51.100.7', 'Agent/1')));
    }

    #[Test]
    public function theNonceIsStoredForItsLifetimeUnderItsTag(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('nonce_'),
            self::isString(),
            ['webmcp_nonce'],
            NonceStore::LIFETIME,
        );

        (new NonceStore($cache, new Random()))->issue($this->request('198.51.100.7', 'Agent/1'), 1, []);
    }

    private function request(string $address, string $userAgent): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => 'User-Agent' === $name ? $userAgent : '',
        );
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $address]);

        return $request;
    }
}
