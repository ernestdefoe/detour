<?php

namespace Ernestdefoe\Detour\Rules;

use Ernestdefoe\Detour\Url;

/**
 * Turns rule rows into the plain array the middleware matches against, which
 * is what sits in the cache. Nothing in it needs the database.
 *
 * Shape: [mode => ['exact' => [key => rule], 'prefix' => [rule, …longest
 * first], 'regex' => [rule, …in order]]].
 */
final class Compiler
{
    /** @return array{always: array{exact: array<string, array<string, mixed>>, prefix: list<array<string, mixed>>, regex: list<array<string, mixed>>}, missing: array{exact: array<string, array<string, mixed>>, prefix: list<array<string, mixed>>, regex: list<array<string, mixed>>}} */
    public static function empty(): array
    {
        $phase = ['exact' => [], 'prefix' => [], 'regex' => []];

        return ['always' => $phase, 'missing' => $phase];
    }

    /**
     * Adds one rule (a row as an array, snake_case columns) to a compiled set.
     * Rows must arrive in evaluation order (position, then id) for regexes.
     *
     * @param array<string, mixed> $set
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function add(array $set, array $row): array
    {
        $rule = self::rule($row);

        if ($rule === null) {
            return $set;
        }

        $mode = $row['mode'] === 'missing' ? 'missing' : 'always';

        switch ($rule['type']) {
            case 'exact':
                // The first rule for a key wins, as it would have when matching.
                $set[$mode]['exact'][$rule['match']] ??= $rule;
                break;
            case 'prefix':
                // Longest first, kept sorted as rules arrive (one sort per add
                // made compiling thousands of rules quadratic).
                $list = $set[$mode]['prefix'];
                $length = strlen($rule['match']);
                [$low, $high] = [0, count($list)];
                while ($low < $high) {
                    $mid = intdiv($low + $high, 2);
                    strlen($list[$mid]['match']) >= $length ? $low = $mid + 1 : $high = $mid;
                }
                array_splice($list, $low, 0, [$rule]);
                $set[$mode]['prefix'] = $list;
                break;
            default:
                $set[$mode]['regex'][] = $rule;
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function rule(array $row): ?array
    {
        $type = (string) $row['type'];
        $source = (string) $row['source'];

        [$path, $query] = Url::split($source);

        $match = match ($type) {
            'exact' => Url::key(Url::subject($path, $query)),
            'prefix' => Url::lower(Url::trimSlash(Url::subject($path, ''))),
            'regex' => self::pattern($source),
            default => null,
        };

        if ($match === null) {
            return null;
        }

        $target = $row['target'] ?? null;

        return [
            'id' => (int) $row['id'],
            'type' => $type,
            'match' => $match,
            'target' => $target === null ? null : (string) $target,
            'status' => (int) $row['status'],
            'keep_query' => (bool) ($row['keep_query'] ?? false),
            'external' => (bool) ($row['external'] ?? false),
            'lookup' => $row['lookup'] ?? null,
            'run_id' => isset($row['run_id']) ? (int) $row['run_id'] : null,
        ];
    }

    /**
     * A rule's regular expression, delimited and case-insensitive, or null when
     * it does not compile.
     */
    public static function pattern(string $body): ?string
    {
        if ($body === '' || strlen($body) > 1000) {
            return null;
        }

        // Escape every `~` that is not already escaped, so it can delimit.
        $escaped = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\~', $body);
        $pattern = '~'.$escaped.'~iu';

        set_error_handler(fn () => true);
        try {
            $ok = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        return $ok ? $pattern : null;
    }
}
