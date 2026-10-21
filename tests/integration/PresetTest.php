<?php

namespace Ernestdefoe\Detour\Tests\integration;

use Ernestdefoe\Detour\Redirect;
use PHPUnit\Framework\Attributes\Test;

class PresetTest extends DetourTestCase
{
    #[Test]
    public function phpbb_with_kept_ids(): void
    {
        $this->assertSame(4, $this->json($this->api('POST', '/presets', ['platform' => 'phpbb']))['created']);

        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/viewtopic.php?f=2&t=1')));
        $this->assertSame(self::BASE.'/d/1/2', $this->location($this->get('/viewtopic.php?p=2')));
        $this->assertSame(self::BASE.'/t/roses', $this->location($this->get('/viewforum.php?f=1')));
        $this->assertSame(self::BASE.'/u/normal', $this->location($this->get('/memberlist.php?mode=viewprofile&u=2')));
    }

    #[Test]
    public function private_content_is_never_given_away(): void
    {
        $this->api('POST', '/presets', ['platform' => 'phpbb']);

        // Post 3 is in a private discussion; tag 2 is restricted.
        $this->assertSame(404, $this->get('/viewtopic.php?p=3')->getStatusCode());
        $this->assertSame(404, $this->get('/viewforum.php?f=2')->getStatusCode());
    }

    #[Test]
    public function tags_are_not_looked_up_while_tags_is_off(): void
    {
        $this->extensions = ['ernestdefoe-detour'];
        $this->api('POST', '/presets', ['platform' => 'phpbb']);

        $this->assertSame(404, $this->get('/viewforum.php?f=2')->getStatusCode());
        $this->assertSame(404, $this->get('/viewforum.php?f=1')->getStatusCode());
    }

    #[Test]
    public function reapplying_replaces_and_remove_removes(): void
    {
        $this->api('POST', '/presets', ['platform' => 'xenforo']);
        $this->api('POST', '/presets', ['platform' => 'xenforo']);
        $this->assertSame(count(\Ernestdefoe\Detour\Rules\Presets::for('xenforo')), Redirect::query()->where('preset', 'xenforo')->count());

        $this->api('POST', '/presets', ['platform' => 'xenforo', 'remove' => true]);
        $this->assertSame(0, Redirect::query()->count());

        $this->assertSame(422, $this->api('POST', '/presets', ['platform' => 'nope'])->getStatusCode());
    }

    #[Test]
    public function preset_rules_never_take_flarums_own_pages(): void
    {
        $this->api('POST', '/presets', ['platform' => 'discourse']);

        // Flarum's own tag page and profile, which Discourse's shapes resemble.
        $this->assertSame(200, $this->get('/t/roses')->getStatusCode());
        $this->assertSame(200, $this->get('/u/normal')->getStatusCode());

        $this->assertSame(self::BASE.'/d/1/2', $this->location($this->get('/t/1/2')));
        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/t/welcome-to-the-garden-club/1')));
        $this->assertSame(self::BASE.'/t/roses', $this->location($this->get('/c/flowers/1')));
    }

    #[Test]
    public function maps_through_an_importer_run(): void
    {
        $this->app();

        // The Importer's tables come from tests/integration/setup.php.
        $this->database()->table('importer_runs')->insert(['id' => 7, 'source' => 'vbulletin', 'state' => '{}', 'status' => 'done']);
        $this->database()->table('importer_map')->insert([
            ['run_id' => 7, 'kind' => 'topic', 'source_id' => '500', 'target_id' => 1],
            ['run_id' => 7, 'kind' => 'tag', 'source_id' => '40', 'target_id' => 1],
            ['run_id' => 7, 'kind' => 'user', 'source_id' => '90', 'target_id' => 2],
            ['run_id' => 6, 'kind' => 'topic', 'source_id' => '501', 'target_id' => 1],
        ]);

        $presets = $this->json($this->api('GET', '/presets'));
        $this->assertSame(7, $presets['runs'][0]['id']);
        $this->assertSame(1, $presets['runs'][0]['mapped']['topic']);

        $this->assertSame(422, $this->api('POST', '/presets', ['platform' => 'vbulletin', 'runId' => 99])->getStatusCode());
        $this->api('POST', '/presets', ['platform' => 'vbulletin', 'runId' => 7]);

        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/showthread.php?t=500')));
        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/threads/500-old-title')));
        $this->assertSame(self::BASE.'/t/roses', $this->location($this->get('/forumdisplay.php?f=40')));
        $this->assertSame(self::BASE.'/u/normal', $this->location($this->get('/member.php?u=90')));
        // Another run's ids, and ids the run never mapped, are not guessed.
        $this->assertSame(404, $this->get('/showthread.php?t=501')->getStatusCode());
        $this->assertSame(404, $this->get('/showthread.php?t=1')->getStatusCode());
    }

    #[Test]
    public function every_preset_example_matches_its_own_rule(): void
    {
        foreach (\Ernestdefoe\Detour\Rules\Presets::all() as $platform => $rules) {
            foreach ($rules as [$lookup, $pattern, $example]) {
                $compiled = \Ernestdefoe\Detour\Rules\Compiler::pattern($pattern);
                $this->assertNotNull($compiled, "$platform $pattern");
                [$p, $q] = array_pad(explode('?', $example, 2), 2, '');
                $this->assertSame(1, preg_match($compiled, \Ernestdefoe\Detour\Url::subject($p, $q)), "$platform: $example");
                $this->assertSame(0, preg_match($compiled, '/'), "$platform: $pattern matches the home page");
            }
        }
    }
}
