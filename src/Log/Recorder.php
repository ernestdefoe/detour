<?php

namespace Ernestdefoe\Detour\Log;

use Carbon\Carbon;
use Ernestdefoe\Detour\Settings;
use Ernestdefoe\Detour\Url;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Keeps the 404 log: one row per address, its hit count, when it was first and
 * last seen and its last few referrers. A hit is an update of that row, never
 * a new one, and the table is pruned to a cap so a scanner cannot grow it.
 */
class Recorder
{
    /** Requests for files Flarum never serves itself: a missing one is not a broken page. */
    private const ASSET = '~\.(?:js|mjs|css|map|png|jpe?g|gif|svg|ico|webp|avif|bmp|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|wav|txt)$~i';

    private const NOISE = ['/favicon', '/apple-touch-icon', '/browserconfig.xml', '/ads.txt', '/robots.txt', '/sitemap'];

    private const BOTS = '~bot\b|bot/|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|headless|lighthouse|python|curl|wget|httpclient|okhttp|go-http|java/|libwww|scrapy|monitor|uptime|scan~i';

    /** Once in this many new rows, the table is pruned. */
    private const PRUNE_EVERY = 25;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function shouldLog(ServerRequestInterface $request): bool
    {
        if (! $this->setting('log')) {
            return false;
        }

        $path = Url::lower(Url::subject($request->getUri()->getPath(), ''));

        if (preg_match(self::ASSET, $path)) {
            return false;
        }

        foreach (self::NOISE as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        foreach (preg_split('~\R~', (string) $this->settings->get(Settings::key('ignore_paths'))) ?: [] as $line) {
            $line = Url::lower(trim($line));
            if ($line !== '' && str_contains($path, $line)) {
                return false;
            }
        }

        if ($this->setting('ignore_bots')) {
            $agent = $request->getHeaderLine('User-Agent');
            if ($agent === '' || preg_match(self::BOTS, $agent)) {
                return false;
            }
        }

        return true;
    }

    public function record(ServerRequestInterface $request): void
    {
        $uri = $request->getUri();
        $url = $uri->getPath() === '' ? '/' : $uri->getPath();
        if ($uri->getQuery() !== '') {
            $url .= '?'.$uri->getQuery();
        }
        $url = mb_strcut($url, 0, 1000, 'UTF-8');
        $hash = Missing::hash($url);
        $referrer = $this->referrer($request->getHeaderLine('Referer'));
        $now = Carbon::now();

        $table = $this->db->table('detour_missing');
        $row = $table->clone()->where('url_hash', $hash)->first(['id', 'referrers']);

        if ($row) {
            $this->bump((int) $row->id, $row->referrers, $referrer, $now);

            return;
        }

        try {
            $id = $table->clone()->insertGetId([
                'url_hash' => $hash,
                'url' => $url,
                'hits' => 1,
                'referrers' => $referrer === null ? null : json_encode([$referrer], JSON_UNESCAPED_SLASHES),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'ignored' => false,
            ]);
        } catch (QueryException) {
            // Another request inserted it first.
            $row = $table->clone()->where('url_hash', $hash)->first(['id', 'referrers']);
            if ($row) {
                $this->bump((int) $row->id, $row->referrers, $referrer, $now);
            }

            return;
        }

        // Every so many new rows, by id, so the cap holds within that many.
        if ($id % self::PRUNE_EVERY === 0) {
            $this->prune();
        }
    }

    private function bump(int $id, ?string $referrers, ?string $referrer, Carbon $now): void
    {
        $extra = ['last_seen_at' => $now];

        if ($referrer !== null) {
            $list = json_decode((string) $referrers, true);
            $list = is_array($list) ? array_values(array_filter($list, 'is_string')) : [];

            if (($list[0] ?? null) !== $referrer) {
                $list = array_slice(array_values(array_unique([$referrer, ...$list])), 0, 5);
                $extra['referrers'] = json_encode($list, JSON_UNESCAPED_SLASHES);
            }
        }

        $this->db->table('detour_missing')->where('id', $id)->increment('hits', 1, $extra);
    }

    /**
     * Drops rows not seen for the retention period, then the least-hit rows
     * above the cap: a flood of one-off addresses evicts itself, not the
     * addresses people keep arriving at.
     */
    public function prune(): void
    {
        $days = max(1, (int) $this->settings->get(Settings::key('retention_days')));
        $this->db->table('detour_missing')->where('last_seen_at', '<', Carbon::now()->subDays($days))->delete();

        $cap = max(100, (int) $this->settings->get(Settings::key('max_rows')));
        $excess = $this->db->table('detour_missing')->count() - $cap;

        while ($excess > 0) {
            $ids = $this->db->table('detour_missing')
                ->orderBy('hits')
                ->orderBy('last_seen_at')
                ->limit(min($excess, 500))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $this->db->table('detour_missing')->whereIn('id', $ids)->delete();
            $excess -= count($ids);
        }
    }

    /** The referring page without its query or fragment (which can carry tokens), or null. */
    private function referrer(string $header): ?string
    {
        if ($header === '' || ! preg_match('~^https?://~i', $header) || Url::hasControl($header) || ! mb_check_encoding($header, 'UTF-8')) {
            return null;
        }

        $parts = parse_url($header);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $clean = strtolower($parts['scheme'] ?? 'http').'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');

        return mb_strcut($clean, 0, 255, 'UTF-8');
    }

    private function setting(string $name): bool
    {
        return (bool) (int) $this->settings->get(Settings::key($name));
    }
}
