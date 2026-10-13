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
     * The port exists so that nonce issuing (Presentation) and nonce
     * validation (the API middleware) hash the same identity. The expected
     * value is therefore spelled out as the middleware's recipe — if the port
     * ever drifts from it, every issued nonce stops validating.
     */
    #[Test]
    public function theCurrentRequestIsFingerprintedWithTheRecipeTheMiddlewareValidatesAgainst(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.7']);
        $request->method('getHeaderLine')->with('User-Agent')->willReturn('Agent/2.0');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        self::assertSame(
            hash('sha256', '203.0.113.7|Agent/2.0'),
            new ClientFingerprint()->ofCurrentRequest(),
        );
    }

    /**
     * Without a request there is no client. Falling back to some constant
     * would bind every nonce to the same shared identity — the exact failure
     * mode the fingerprint was hardened against behind the reverse proxy — so
     * the port must refuse instead of inventing an answer.
     */
    #[Test]
    public function withoutARequestNoFingerprintIsInvented(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $this->expectException(\RuntimeException::class);

        new ClientFingerprint()->ofCurrentRequest();
    }
}
