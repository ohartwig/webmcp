<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Http;

/**
 * Reduces a User-Agent header to its product tokens for the audit log.
 *
 * A full User-Agent string is a fingerprint: the parenthesised comments
 * carry the operating system, its version, the device model and, on some
 * platforms, build identifiers. The log needs to tell a browser from a bot
 * and one client family from another, which the product tokens
 * ("Mozilla/5.0 Chrome/120.0 Safari/537.36") do on their own.
 *
 * Everything in parentheses is dropped, nested or not; what remains is
 * filtered to printable ASCII tokens of the form name or name/version and
 * capped in length.
 */
final class UserAgentReducer
{
    public const int MAX_LENGTH = 120;

    private const string TOKEN_PATTERN = '#^[A-Za-z0-9._+!~-]+(?:/[A-Za-z0-9._+!~-]+)?$#';

    public static function reduce(string $userAgent): string
    {
        $withoutComments = self::stripComments($userAgent);

        $parts = preg_split('/\s+/', $withoutComments);
        if (false === $parts) {
            return '';
        }

        $tokens = [];
        foreach ($parts as $token) {
            if ('' === $token || 1 !== preg_match(self::TOKEN_PATTERN, $token)) {
                continue;
            }
            $tokens[] = $token;
        }

        return substr(implode(' ', $tokens), 0, self::MAX_LENGTH);
    }

    /**
     * Removes every parenthesised comment, including nested ones and an
     * unbalanced tail, without a regular expression that could be made to
     * backtrack on a hostile header.
     */
    private static function stripComments(string $value): string
    {
        $result = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ('(' === $char) {
                ++$depth;
                continue;
            }
            if (')' === $char) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if (0 === $depth) {
                $result .= $char;
            }
        }

        return $result;
    }
}
