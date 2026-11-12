<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Identifies a caller well enough to bind a nonce and count requests against.
 *
 * The address must come from `getIndpEnv()`, not from the raw server params.
 * Behind the reverse proxy every request arrives from the proxy's own address,
 * so `$_SERVER['REMOTE_ADDR']` is the same value for every visitor. That has
 * two consequences, and both were live here:
 *
 *  - the nonce was bound to (proxy address | user agent), which is to say
 *    bound to the user agent alone
 *  - the invocation log hashed that same constant, so every entry in the audit
 *    trail carried an identical "client" hash
 *
 * `getIndpEnv('REMOTE_ADDR')` honours the configured `reverseProxyIP` trust and
 * returns the real client address. Trusting the forwarded header directly would
 * be worse than the constant — a caller could then choose its own identity and
 * step around both the binding and the rate limit.
 *
 * Nonce issuing and nonce validation both go through `NonceStore`, which
 * calls `of()` on both sides; that is what keeps them computing the identical
 * value. Until 1.9.0 the page listener issued nonces through an Application
 * port onto this class; nonces no longer come from the page, and the port
 * went with it.
 */
final class ClientFingerprint
{
    public static function ip(ServerRequestInterface $request): string
    {
        // getIndpEnv() is declared as returning anything the environment might
        // hold, including arrays and false. Narrow instead of casting: a cast
        // would turn an unexpected array into the string "Array" and bind every
        // caller to the same identity.
        $ip = GeneralUtility::getIndpEnv('REMOTE_ADDR');
        if (is_string($ip) && '' !== $ip) {
            return $ip;
        }

        // Console context and unit tests have no environment to ask.
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    public static function of(ServerRequestInterface $request): string
    {
        return hash('sha256', self::ip($request) . '|' . $request->getHeaderLine('User-Agent'));
    }

    /**
     * For the audit trail: identifies a caller across entries without storing
     * the address itself.
     *
     * Keyed, not a plain digest. An unsalted SHA-256 of an IPv4 address is a
     * pseudonym at best: the whole address space hashes in seconds, so whoever
     * reads the table reads the addresses. With a key derived from the
     * installation's encryptionKey the hash still links one caller's entries
     * to each other, and that is all the audit trail needs from it.
     */
    public static function ipHash(ServerRequestInterface $request): string
    {
        $encryptionKey = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        $key = hash('sha256', $encryptionKey . '|webmcp-log-ip', true);

        return hash_hmac('sha256', self::ip($request), $key);
    }
}
