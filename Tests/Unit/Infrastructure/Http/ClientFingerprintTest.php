<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Infrastructure\Http\ClientFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ClientFingerprintTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * The recipe nonces are bound with, spelled out: address, a bar, the
     * User-Agent, SHA-256. Nonces already stored were bound with it, so a
     * change to it invalidates every one of them.
     */
    #[Test]
    public function aRequestIsFingerprintedByAddressAndUserAgent(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.7']);
        $request->method('getHeaderLine')->with('User-Agent')->willReturn('Agent/2.0');

        self::assertSame(hash('sha256', '203.0.113.7|Agent/2.0'), ClientFingerprint::of($request));
    }

    /**
     * The audit log keeps this value for every call. A plain digest of an
     * IPv4 address is reversible by enumerating the address space, so the
     * hash has to depend on a secret the table does not contain.
     */
    #[Test]
    public function theLoggedAddressHashIsKeyedByTheInstallation(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.7']);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'key-one';
        $first = ClientFingerprint::ipHash($request);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'key-two';
        $second = ClientFingerprint::ipHash($request);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);

        self::assertNotSame(hash('sha256', '203.0.113.7'), $first, 'must not be the plain digest');
        self::assertNotSame($first, $second, 'must depend on the key');
        self::assertSame(64, strlen($first), 'stays a 64-character hex value for the existing column');
    }

    /**
     * Same caller, same key, same hash: the log still has to link one
     * caller's entries to each other, or it is no audit trail.
     */
    #[Test]
    public function theLoggedAddressHashIsStableForOneCaller(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.7']);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'key-one';
        $first = ClientFingerprint::ipHash($request);
        $second = ClientFingerprint::ipHash($request);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);

        self::assertSame($first, $second);
    }
}
