<?php

namespace Ernestdefoe\Detour\Rules;

use Ernestdefoe\Detour\Redirect;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The compiled rules, from the cache: one cache read per request and no
 * query. The database is read only after a rule changes (or the cache was
 * cleared), and the next request puts the result back.
 */
class RuleSet
{
    public const CACHE_KEY = 'ernestdefoe-detour.rules';

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(
        protected Cache $cache,
        protected LoggerInterface $log,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $set = $this->cache->get(self::CACHE_KEY);

        if (! is_array($set)) {
            $set = $this->build();
            $this->cache->forever(self::CACHE_KEY, $set);
        }

        return $this->memo = $set;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $set = Compiler::empty();

        try {
            $rows = Redirect::query()
                ->where('enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'type', 'source', 'target', 'status', 'mode', 'keep_query', 'external', 'lookup', 'run_id']);
        } catch (Throwable $e) {
            // Before the migration ran, or the table was lost: redirect nothing.
            $this->log->warning('[detour] could not read redirects: '.$e->getMessage());

            return $set;
        }

        foreach ($rows as $row) {
            $set = Compiler::add($set, $row->getAttributes());
        }

        return $set;
    }

    public function flush(): void
    {
        $this->memo = null;
        $this->cache->forget(self::CACHE_KEY);
    }
}
