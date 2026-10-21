<?php

namespace Ernestdefoe\Detour\Rules;

use Ernestdefoe\Detour\Url;

/**
 * Finds the rule for an address: exact first (with its query, then without),
 * then the longest prefix, then regular expressions in order.
 *
 * A target that is itself redirected by an "always" rule is followed (up to
 * five hops) so the visitor and search engines get ONE redirect to the final
 * address; a loop is refused instead of sending anyone round in circles.
 */
class Matcher
{
    public const MAX_HOPS = 5;

    public function __construct(
        protected Lookup $lookup,
    ) {
    }

    /**
     * @param array<string, mixed> $set compiled rules
     * @param string $phase always | missing
     * @param string $rawQuery the request's query, as sent (for "keep query")
     * @param list<string> $follow phases whose rules a chain may pass through:
     *                             at request time only "always" ones can be known
     *                             to apply; saving a rule checks both
     */
    public function match(array $set, string $phase, string $path, string $rawQuery, string $base, array $follow = ['always']): ?Hit
    {
        $subject = Url::subject($path, $rawQuery);
        $hit = $this->first($set[$phase], $subject, $rawQuery, $base);

        if ($hit === null || $hit->target === null || ! str_starts_with($hit->target, $base.'/')) {
            return $hit;
        }

        /*
         * Collapse chains, refusing loops. An address counts as seen again only
         * when it is the very same text: a rule that just drops a trailing
         * slash or changes case is a redirect, not a loop, and a rule that
         * really does send an address back to itself is caught one hop later,
         * when it produces the same target again.
         */
        $seen = [$subject => true];
        $chain = [];
        $current = $hit;

        for ($hop = 0; ; $hop++) {
            [$nextPath, $nextQuery] = Url::split(substr((string) $current->target, strlen($base)));
            $nextSubject = Url::subject($nextPath, $nextQuery);

            if (isset($seen[$nextSubject])) {
                return null;
            }
            $seen[$nextSubject] = true;

            $further = null;
            foreach ($follow as $through) {
                if ($further = $this->first($set[$through], $nextSubject, $nextQuery, $base)) {
                    break;
                }
            }

            if ($further === null) {
                break;
            }

            if ($hop === self::MAX_HOPS) {
                return null;
            }

            $chain[] = $further->rule['id'];
            $current = $further;

            if ($further->target === null || ! str_starts_with($further->target, $base.'/')) {
                break;
            }
        }

        return $chain === [] ? $hit : new Hit($hit->rule, $current->target, $current->target === null ? 410 : $hit->status, $chain);
    }

    /**
     * The id of the first rule (either phase) whose pattern covers an address,
     * without looking anything up: for the 404 log's "covered by" column.
     *
     * @param array<string, mixed> $set
     */
    public function covering(array $set, string $path, string $rawQuery): ?int
    {
        $subject = Url::subject($path, $rawQuery);
        $key = Url::key($subject);
        $pathKey = Url::lower(Url::trimSlash(explode('?', $subject, 2)[0]));

        foreach (['always', 'missing'] as $phase) {
            $rule = $set[$phase]['exact'][$key] ?? $set[$phase]['exact'][$pathKey] ?? null;
            if ($rule !== null) {
                return $rule['id'];
            }
            foreach ($set[$phase]['prefix'] as $rule) {
                if ($pathKey === $rule['match'] || str_starts_with($pathKey, $rule['match'].'/')) {
                    return $rule['id'];
                }
            }
            foreach ($set[$phase]['regex'] as $rule) {
                if (@preg_match($rule['match'], $subject) === 1) {
                    return $rule['id'];
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $phase */
    public function first(array $phase, string $subject, string $rawQuery, string $base): ?Hit
    {
        [$path] = explode('?', $subject, 2);
        $key = Url::key($subject);
        $pathKey = Url::lower(Url::trimSlash($path));

        $exact = $phase['exact'][$key] ?? ($key !== $pathKey ? ($phase['exact'][$pathKey] ?? null) : null);

        if ($exact !== null && ($hit = $this->build($exact, $exact['target'], [], $rawQuery, $base))) {
            return $hit;
        }

        foreach ($phase['prefix'] as $rule) {
            $prefix = $rule['match'];
            if ($pathKey === $prefix || str_starts_with($pathKey, $prefix.'/')) {
                // The rest of the path, in its original case when lower-casing
                // left the prefix the same length (it nearly always does).
                $trimmed = Url::trimSlash($path);
                $rest = Url::lower(substr($trimmed, 0, strlen($prefix))) === $prefix
                    ? substr($trimmed, strlen($prefix))
                    : substr($pathKey, strlen($prefix));
                $target = $rule['target'] === null ? null : self::joinPath($rule['target'], Url::encode($rest));

                if ($hit = $this->build($rule, $target, [], $rawQuery, $base)) {
                    return $hit;
                }
            }
        }

        foreach ($phase['regex'] as $rule) {
            if (@preg_match($rule['match'], $subject, $m) !== 1) {
                continue;
            }

            if ($rule['lookup'] !== null) {
                $found = $this->lookup->find($rule['lookup'], $m[1] ?? '', $m[2] ?? '', $rule['run_id']);
                if ($found === null) {
                    continue;
                }
                $target = $found;
            } else {
                $target = $rule['target'] === null ? null : self::substitute($rule['target'], $m);
            }

            if ($hit = $this->build($rule, $target, $m, $rawQuery, $base)) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<int|string, string> $m
     */
    private function build(array $rule, ?string $target, array $m, string $rawQuery, string $base): ?Hit
    {
        if ((int) $rule['status'] === 410) {
            return new Hit($rule, null, 410);
        }

        if ($target === null || $target === '') {
            return null;
        }

        if ($rule['keep_query']) {
            $target = Url::withQuery($target, $rawQuery);
        }

        if (str_starts_with($target, '/')) {
            if (! Url::safeInternal($target)) {
                return null;
            }

            return new Hit($rule, rtrim($base, '/').$target, (int) $rule['status']);
        }

        // External: only when the admin chose it, and only to the host they wrote.
        if (! $rule['external'] || Url::hasControl($target) || preg_match('~\s~', $target)) {
            return null;
        }

        $host = Url::externalTemplate($target);
        $parts = parse_url($target);

        if ($host === null || ! is_array($parts) || Url::lower(($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')) !== $host) {
            return null;
        }

        return new Hit($rule, $target, (int) $rule['status']);
    }

    /**
     * Puts `$1` / `${1}` captures, encoded, into a target.
     *
     * @param array<int|string, string> $m
     */
    public static function substitute(string $target, array $m): string
    {
        return (string) preg_replace_callback('~\$(?:\{(\d)\}|(\d))~', function ($x) use ($m) {
            $n = (int) ($x[1] !== '' ? $x[1] : $x[2]);

            return Url::encode($m[$n] ?? '');
        }, $target);
    }

    /** `/new` + `/rest` → `/new/rest`, keeping a query or fragment on the target at the end. */
    public static function joinPath(string $target, string $rest): string
    {
        if ($rest === '') {
            return $target;
        }

        preg_match('~^([^?#]*)(.*)$~s', $target, $parts);

        return rtrim($parts[1], '/').$rest.$parts[2];
    }
}
