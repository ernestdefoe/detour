<?php

namespace Ernestdefoe\Detour\Tests\integration;

use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\Compiler;
use Ernestdefoe\Detour\Url;
use PHPUnit\Framework\Attributes\Test;

/** One test per defect the pre-release adversarial review found; each fails on the code before its fix. */
class ReviewFindingsTest extends DetourTestCase
{
    #[Test]
    public function saving_again_never_decodes_a_source_twice(): void
    {
        $id = $this->json($this->api('POST', '/redirects', ['source' => '/search?q=c%2B%2B', 'target' => '/d/1']))['data']['id'];

        $this->api('PATCH', "/redirects/$id", ['enabled' => false]);
        $this->api('PATCH', "/redirects/$id", ['enabled' => true, 'source' => '/search?q=c%2B%2B', 'note' => 'edited in the form']);

        $this->assertSame('/search?q=c%2B%2B', Redirect::query()->find($id)->source);
        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/search?q=c%2B%2B')));
        $this->assertSame(404, $this->get('/search?q=c')->getStatusCode());
    }

    #[Test]
    public function latin1_addresses_from_old_forums_still_match(): void
    {
        $this->api('POST', '/presets', ['platform' => 'phpbb']);
        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/viewtopic.php?t=1&hilit=caf%E9')));

        $created = $this->api('POST', '/redirects', ['source' => '/caf%E9', 'target' => '/d/1']);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/caf%E9')));

        // Logged as sent, without breaking a strict database.
        $this->get('/old-caf%E9-page');
        $this->assertSame(1, $this->database()->table('detour_missing')->where('url', '/old-caf%E9-page')->count());
    }

    #[Test]
    public function two_missing_rules_pointing_at_each_other_are_refused(): void
    {
        $this->assertSame(201, $this->api('POST', '/redirects', ['source' => '/x', 'target' => '/y', 'mode' => 'missing'])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/redirects', ['source' => '/y', 'target' => '/x', 'mode' => 'missing'])->getStatusCode());
    }

    #[Test]
    public function dropping_a_trailing_slash_is_a_redirect_not_a_loop(): void
    {
        $this->rule(['type' => 'regex', 'source' => '^/(.+)/$', 'target' => '/$1']);
        $this->rule(['source' => '/Garden', 'target' => '/garden']);

        $this->assertSame(self::BASE.'/old-page', $this->location($this->get('/old-page/')));
        // Case-insensitive rule to its own address in another case really is a loop.
        $this->assertSame(404, $this->get('/garden')->getStatusCode());
    }

    #[Test]
    public function the_vbulletin5_preset_leaves_flarums_own_addresses_alone(): void
    {
        $this->api('POST', '/presets', ['platform' => 'vbulletin5']);

        // Private to a guest, so "missing"; it must stay a 404, not be sent anywhere.
        $this->assertSame(404, $this->get('/d/2-secret-garden-plans')->getStatusCode());
        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/forum/general/1-welcome/page2')));
    }

    #[Test]
    public function missing_phase_redirects_are_never_cached(): void
    {
        $this->rule(['source' => '/gone-page', 'target' => '/d/1', 'mode' => 'missing']);
        $this->rule(['source' => '/moved-page', 'target' => '/d/1']);

        $this->assertSame('private, no-store', $this->get('/gone-page')->getHeaderLine('Cache-Control'));
        $this->assertSame('public, max-age=86400', $this->get('/moved-page')->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function csv_keeps_a_rules_importer_run_and_preset_and_refuses_unknown_runs(): void
    {
        $this->app();

        $this->database()->table('importer_runs')->insert(['id' => 3, 'source' => 'phpbb']);
        $this->api('POST', '/presets', ['platform' => 'phpbb', 'runId' => 3]);

        $csv = (string) $this->api('GET', '/export')->getBody();
        Redirect::query()->delete();

        $result = $this->json($this->api('POST', '/import', ['csv' => $csv]));
        $this->assertSame(4, $result['created'], json_encode($result));
        $this->assertSame([3], Redirect::query()->distinct()->pluck('run_id')->all());
        $this->assertSame(['phpbb'], Redirect::query()->distinct()->pluck('preset')->all());

        Redirect::query()->delete();
        $this->database()->table('importer_runs')->delete();
        $result = $this->json($this->api('POST', '/import', ['csv' => $csv]));
        $this->assertSame(0, $result['created']);
        $this->assertSame(4, $result['failed']);
    }

    #[Test]
    public function reinstalling_a_preset_keeps_what_the_admin_changed(): void
    {
        $this->api('POST', '/presets', ['platform' => 'phpbb']);
        $post = Redirect::query()->where('lookup', 'post')->first();
        $this->api('PATCH', "/redirects/$post->id", ['enabled' => false, 'status' => 302]);

        $this->api('POST', '/presets', ['platform' => 'phpbb']);

        $again = Redirect::query()->where('lookup', 'post')->first();
        $this->assertFalse($again->enabled);
        $this->assertSame(302, $again->status);
    }

    #[Test]
    public function compiling_thousands_of_prefix_rules_stays_fast(): void
    {
        $set = Compiler::empty();
        $start = microtime(true);
        for ($i = 0; $i < 5000; $i++) {
            $set = Compiler::add($set, ['id' => $i, 'type' => 'prefix', 'source' => '/p'.str_repeat('x', $i % 40).$i, 'target' => '/d/1', 'status' => 301, 'mode' => 'always']);
        }

        $this->assertLessThan(2.0, microtime(true) - $start);
        $lengths = array_map(fn ($r) => strlen($r['match']), $set['always']['prefix']);
        $sorted = $lengths;
        rsort($sorted);
        $this->assertSame($sorted, $lengths, 'longest prefix first');
    }

    #[Test]
    public function the_forums_own_address_and_folder_are_cut_from_a_pasted_url(): void
    {
        $this->assertSame('/old?a=1', Url::raw('https://example.com/forum/old?a=1#x', 'https://example.com/forum'));
        $this->assertSame('/forum/old', Url::raw('https://elsewhere.example/forum/old', 'https://example.com/forum'));
        $this->assertSame('/old%20page', Url::raw('/old page'));
        $this->assertNull(Url::raw("/old\tpage"));
    }

    #[Test]
    public function search_treats_underscores_and_percents_literally_and_ignores_case(): void
    {
        $this->rule(['source' => '/a', 'target' => '/d/1', 'note' => 'Old_Name']);
        $this->rule(['source' => '/b', 'target' => '/d/1', 'note' => 'oldxname']);

        $found = $this->json($this->api('GET', '/redirects', null, 1, ['q' => 'old_name']))['data'];
        $this->assertSame(['/a'], array_column($found, 'source'));
        $this->assertSame([], $this->json($this->api('GET', '/redirects', null, 1, ['q' => '%']))['data']);
    }

    #[Test]
    public function more_old_address_shapes_resolve(): void
    {
        $this->api('POST', '/presets', ['platform' => 'mybb']);
        $this->api('POST', '/presets', ['platform' => 'discourse']);
        $this->api('POST', '/presets', ['platform' => 'xenforo']);
        $this->api('POST', '/presets', ['platform' => 'vanilla']);

        $this->assertSame(self::BASE.'/d/1/2', $this->location($this->get('/thread-1-post-2.html')));
        $this->assertSame(self::BASE.'/t/roses', $this->location($this->get('/c/flowers/1/l/latest')));
        $this->assertSame(self::BASE.'/d/1/2', $this->location($this->get('/goto/post?id=2')));
        $this->assertSame(self::BASE.'/u/normal', $this->location($this->get('/profile/comments/2/normal')));
        $this->assertSame(self::BASE.'/u/normal', $this->location($this->get('/profile/NORMAL')));
    }
}
