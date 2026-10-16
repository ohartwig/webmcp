<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

/**
 * Counts tool calls per caller and per site and says when either has had
 * enough for the current window.
 *
 * The caller is identified by its network address alone. The User-Agent
 * used to be half of the key, and a header the caller chooses is not a key:
 * every page fetch minted a fresh nonce for whatever User-Agent asked, and a
 * page fetch is cheaper than the sixty tool calls it unlocked.
 */
interface RateLimiterInterface
{
    /**
     * Records one call and reports whether it is within both limits. The
     * call is counted whether or not it is allowed, so a caller over the
     * limit does not get a fresh budget by retrying.
     *
     * @param string $address        the client's network address as the request saw it
     * @param string $siteIdentifier the site the call targets, '' when unknown
     */
    public function allow(string $address, string $siteIdentifier): bool;
}
