<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\Random;

/**
 * Issues the nonces that authorise tool calls, and looks them up again.
 *
 * Minting and validation live in one class so that they share one length.
 * Until 1.8.3 they did not: the page listener minted with
 * `generateRandomHexString(32)` — 32 characters, not 32 bytes — and the API
 * middleware demanded 64, so no nonce the extension ever issued was accepted.
 * The listener test hid it by mocking `Random` to return 64 characters.
 *
 * A nonce is bound to the caller's fingerprint (address and User-Agent, see
 * `ClientFingerprint`) and to the page whose tool overrides apply to the
 * calls made with it. It is issued by the API's nonce endpoint, never into a
 * rendered page: a page is held by the page cache and the edge for hours and
 * served to every visitor, so a secret in it belongs to whoever filled the
 * cache.
 */
final readonly class NonceStore
{
    /** Length in hex characters, for minting and validating alike. */
    public const int LENGTH = 64;

    /** Seconds a nonce stays valid after it was issued. */
    public const int LIFETIME = 3600;

    private const string CACHE_PREFIX = 'nonce_';
    private const string CACHE_TAG = 'webmcp_nonce';

    public function __construct(
        private FrontendInterface $cache,
        private Random $random,
    ) {}

    /**
     * @param array<string, bool> $toolOverrides the page TSconfig overrides of $pageId, resolved by the server
     */
    public function issue(ServerRequestInterface $request, int $pageId, array $toolOverrides): string
    {
        $nonce = $this->random->generateRandomHexString(self::LENGTH);

        $this->cache->set(self::CACHE_PREFIX . $nonce, json_encode([
            'nonce' => $nonce,
            'fingerprint' => ClientFingerprint::of($request),
            'created' => time(),
            'pageId' => $pageId,
            'toolOverrides' => $toolOverrides,
        ], JSON_THROW_ON_ERROR), [self::CACHE_TAG], self::LIFETIME);

        return $nonce;
    }

    public static function isWellFormed(string $nonce): bool
    {
        return 1 === preg_match('/^[a-f0-9]{' . self::LENGTH . '}$/', $nonce);
    }

    /**
     * The nonce's stored data when the nonce is valid for this caller, else null.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $nonce, ServerRequestInterface $request): ?array
    {
        if (!self::isWellFormed($nonce)) {
            return null;
        }

        $cached = $this->cache->get(self::CACHE_PREFIX . $nonce);
        if (!is_string($cached)) {
            return null;
        }

        $data = json_decode($cached, true);
        if (!is_array($data)) {
            return null;
        }

        $fingerprint = $data['fingerprint'] ?? null;
        if (!is_string($fingerprint) || !hash_equals($fingerprint, ClientFingerprint::of($request))) {
            return null;
        }

        return $data;
    }
}
