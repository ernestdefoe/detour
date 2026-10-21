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

    /**
     * The decoded subject a rule is matched against: path, then `?query` when
     * there is one. Old forums often linked in Latin-1 (`caf%E9`), which is not
     * UTF-8 once decoded; such text is read as Windows-1252 so it still matches.
     */
    public static function subject(string $path, string $query): string
    {
        $subject = rawurldecode($path === '' ? '/' : $path);

        if ($query !== '') {
            $subject .= '?'.urldecode($query);
        }

        return mb_check_encoding($subject, 'UTF-8') ? $subject : mb_convert_encoding($subject, 'UTF-8', 'Windows-1252');
    }

    /** A raw source or address split into its path and query. @return array{0: string, 1: string} */
    public static function split(string $raw): array
    {
        [$path, $query] = array_pad(explode('?', explode('#', $raw, 2)[0], 2), 2, '');

        return [$path === '' ? '/' : $path, $query];
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
     * kept as typed: a path with an optional query, never decoded here, so
     * saving it again changes nothing. A full URL keeps only its path and
     * query (and loses this forum's own folder, when it is this forum's
     * address), so an address copied from a browser works.
     *
     * @param string $base the forum's base URL
     */
    public static function raw(string $input, string $base = ''): ?string
    {
        $input = trim($input);

        if (! mb_check_encoding($input, 'UTF-8') || self::hasControl($input)) {
            return null;
        }

        if (preg_match('~^https?://~i', $input)) {
            $base = rtrim($base, '/');
            if ($base !== '' && stripos($input.'/', $base.'/') === 0) {
                $input = substr($input, strlen($base));
                $input = $input === '' || $input[0] !== '/' ? '/'.$input : $input;
            } else {
                $parts = parse_url($input);
                if ($parts === false) {
                    return null;
                }
                $input = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        $input = str_replace(' ', '%20', explode('#', $input, 2)[0]);

        if ($input === '' || $input[0] !== '/' || preg_match('~\s~u', $input)) {
            return null;
        }

        return $input;
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
