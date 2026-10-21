<?php

namespace Ernestdefoe\Detour\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Detour\Log\Missing;
use Ernestdefoe\Detour\Log\Recorder;
use PHPUnit\Framework\Attributes\Test;

class MissingLogTest extends DetourTestCase
{
    #[Test]
    public function one_row_per_address_with_hits_and_referrers_without_queries(): void
    {
        $this->get('/viewtopic.php?t=5', ['Referer' => 'https://www.google.com/search?q=secret-token']);
        $this->get('/viewtopic.php?t=5', ['Referer' => 'https://blog.example/post?id=1#x']);
        $this->get('/viewtopic.php?t=5');

        $rows = Missing::query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('/viewtopic.php?t=5', $rows[0]->url);
        $this->assertSame(3, $rows[0]->hits);
        $this->assertSame(['https://blog.example/post', 'https://www.google.com/search'], $rows[0]->referrerList());
    }

    #[Test]
    public function thrown_not_found_pages_are_logged_too(): void
    {
        $this->get('/d/12345');
        $this->get('/u/nobody-here');

        $this->assertSame(['/d/12345', '/u/nobody-here'], Missing::query()->orderBy('id')->pluck('url')->all());
    }

    #[Test]
    public function bots_assets_noise_and_pages_that_exist_are_not_logged(): void
    {
        $this->get('/old-thing', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
        $this->get('/old-thing', ['User-Agent' => '']);
        $this->get('/missing.png');
        $this->get('/assets/forum-xx.js');
        $this->get('/wp-login.php');
        $this->get('/favicon.ico');
        $this->get('/d/1');
        $this->get('/api/discussions/999', ['Accept' => 'application/json']);

        $this->assertSame(0, Missing::query()->count());
    }

    #[Test]
    public function bots_are_logged_when_the_admin_wants_them(): void
    {
        $this->setting('ernestdefoe-detour.ignore_bots', '0');

        $this->get('/old-thing', ['User-Agent' => 'Googlebot/2.1']);

        $this->assertSame(1, Missing::query()->count());
    }

    #[Test]
    public function logging_can_be_turned_off(): void
    {
        $this->setting('ernestdefoe-detour.log', '0');

        $this->get('/old-thing');

        $this->assertSame(0, Missing::query()->count());
    }

    #[Test]
    public function an_address_a_rule_now_answers_is_not_logged(): void
    {
        $this->rule(['source' => '/old-thing', 'target' => '/d/1', 'mode' => 'missing']);

        $this->get('/old-thing');

        $this->assertSame(0, Missing::query()->count());
    }

    #[Test]
    public function prune_drops_old_rows_then_the_least_hit_above_the_cap(): void
    {
        $this->setting('ernestdefoe-detour.max_rows', '100');
        $this->setting('ernestdefoe-detour.retention_days', '30');
        $this->app();

        $now = Carbon::now();
        $rows = [];
        for ($i = 0; $i < 130; $i++) {
            $rows[] = ['url_hash' => sha1("/x$i"), 'url' => "/x$i", 'hits' => $i, 'first_seen_at' => $now, 'last_seen_at' => $now, 'ignored' => false];
        }
        $rows[] = ['url_hash' => sha1('/stale'), 'url' => '/stale', 'hits' => 1000, 'first_seen_at' => $now->copy()->subDays(60), 'last_seen_at' => $now->copy()->subDays(31), 'ignored' => false];
        $this->database()->table('detour_missing')->insert($rows);

        $this->app()->getContainer()->make(Recorder::class)->prune();

        $this->assertSame(100, Missing::query()->count());
        $this->assertNull(Missing::query()->where('url', '/stale')->first());
        $this->assertSame(30, (int) Missing::query()->min('hits'), 'the 30 least-hit were dropped');
    }

    #[Test]
    public function a_flood_of_new_addresses_stays_under_the_cap(): void
    {
        $this->setting('ernestdefoe-detour.max_rows', '100');

        for ($i = 0; $i < 400; $i++) {
            $this->get("/scan-$i");
        }

        // Pruning runs on every 25th new row, so the table overshoots by fewer than 25.
        $this->assertLessThan(125, Missing::query()->count());
    }
}
