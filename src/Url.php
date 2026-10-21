<?php

namespace Ernestdefoe\Detour;

/**
 * How addresses are compared and built.
 *
 * Every rule matches the DECODED address: `/caf%C3%A9` and `/café` are the
 * same rule, and a capture is plain text that is encoded again when it is put
 * into a target. That one rule keeps a capture from smuggling `%2F%2F` or a
 * line break into a Location header.
 */
final class Url
{
    /** Paths Detour never redirects, logs or serves its page for. */
    public const RESERVED = ['/api', '/admin', '/assets', '/auth', '/login', '/logout', '/register', '/reset', '/confirm', '/notifications/unsubscribe'];

    /** The decoded subject a rule is matched against: path, then `?query` when there is one. */
    public static function subject(string $path, string $query): string
    {
        $subject = rawurldecode($path === '' ? '/' : $path);

        return $query === '' ? $subject : $subject.'?'.urldecode($query);
    }

    /**
     * The key an exact rule is stored under, and a request looked up by:
     * decoded, lower case, without a trailing slash, query pairs sorted.
     */
    public static function key(string $subject): string
    {
        [$path, $query] = array_pad(explode('?', $subject, 2), 2, '');

        $key = self::lower(self::trimSlash($path));

        if ($query !== '') {
            $pairs = array_filter(explode('&', self::lower($query)), fn ($p) => $p !== '');
            sort($pairs);
            if ($pairs) {
                $key .= '?'.implode('&', $pairs);
            }
        }

        return $key;
    }

    public static function trimSlash(string $path): string
    {
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * What an admin typed (or a CSV row or a 404 log entry held) as a source,
     * turned into a decoded path with an optional query. A full URL keeps
     * only its path and query, so an address copied from the old site works.
     */
    public static function source(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('~^https?://~i', $input)) {
            $parts = parse_url($input);
            if ($parts === false) {
                return null;
            }
            $input = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        $input = explode('#', $input, 2)[0];

        if ($input === '' || $input[0] !== '/' || self::hasControl($input)) {
            return null;
        }

        [$path, $query] = array_pad(explode('?', $input, 2), 2, '');

        return self::subject($path, $query);
    }

    public static function reserved(string $decodedPath): bool
    {
        $path = self::lower($decodedPath);

        foreach (self::RESERVED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** Encodes decoded text for a path or query, leaving its slashes. */
    public static function encode(string $text): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $text)));
    }

    /**
     * Whether a built target may go in a Location header as a path on this
     * forum: one leading slash, not followed by another slash or a backslash
     * (either makes it an address on ANOTHER host), and no whitespace or
     * control characters anywhere.
     */
    public static function safeInternal(string $target): bool
    {
        return preg_match('~^/(?![/\\\\])~', $target) === 1
            && preg_match('~[\s\x00-\x1F\x7F\\\\]~', $target) !== 1;
    }

    /** An absolute http(s) address whose host is written out, never a capture. */
    public static function externalTemplate(string $target): ?string
    {
        if (! preg_match('~^https?://([^/?#]+)~i', $target, $m) || self::hasControl($target) || preg_match('~\s~', $target)) {
            return null;
        }

        // A capture in the host part could send visitors anywhere.
        if (str_contains($m[1], '$') || str_contains($m[1], '@')) {
            return null;
        }

        return self::lower($m[1]);
    }

    public static function hasControl(string $s): bool
    {
        return preg_match('~[\x00-\x1F\x7F]~', $s) === 1;
    }

    /** Joins a query onto a target that may already have one. */
    public static function withQuery(string $target, string $query): string
    {
        if ($query === '') {
            return $target;
        }

        [$base, $fragment] = array_pad(explode('#', $target, 2), 2, null);

        return $base.(str_contains($base, '?') ? '&' : '?').$query.($fragment !== null ? '#'.$fragment : '');
    }
}
