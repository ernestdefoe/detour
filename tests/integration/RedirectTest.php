<?php

namespace Ernestdefoe\Detour\Tests\integration;

use PHPUnit\Framework\Attributes\Test;

class RedirectTest extends DetourTestCase
{
    #[Test]
    public function exact_redirects_before_routing_ignoring_case_and_slash(): void
    {
        $this->rule(['source' => '/Old-Page', 'target' => '/d/1']);

        $response = $this->get('/old-page/');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(self::BASE.'/d/1', $this->location($response));
        $this->assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));
        $this->assertSame([], $response->getHeader('Set-Cookie'), 'a redirect starts no session');
    }

    #[Test]
    public function exact_with_query_matches_that_query_in_any_order(): void
    {
        $this->rule(['source' => '/page.php?b=2&a=1', 'target' => '/d/1']);
        $this->rule(['source' => '/page.php', 'target' => '/d/2', 'status' => 302]);

        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/page.php?a=1&b=2')));
        $other = $this->get('/page.php?a=9');
        $this->assertSame(302, $other->getStatusCode());
        $this->assertSame(self::BASE.'/d/2', $this->location($other));
        $this->assertSame('private, no-store', $other->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function prefix_keeps_the_rest_of_the_path_and_respects_segments(): void
    {
        $this->rule(['type' => 'prefix', 'source' => '/old', 'target' => '/t/roses?from=old']);

        $this->assertSame(self::BASE.'/t/roses/Some%20Thing?from=old', $this->location($this->get('/old/Some%20Thing')));
        $this->assertSame(self::BASE.'/t/roses?from=old', $this->location($this->get('/old')));
        $this->assertNotSame(301, $this->get('/older')->getStatusCode());
    }

    #[Test]
    public function regex_substitutes_encoded_captures(): void
    {
        $this->rule(['type' => 'regex', 'source' => '^/archive/(\d+)/(.+)$', 'target' => '/d/$1?q=${2}']);

        $this->assertSame(self::BASE.'/d/12?q=caf%C3%A9%20au%20lait', $this->location($this->get('/archive/12/caf%C3%A9%20au%20lait')));
    }

    #[Test]
    public function a_capture_cannot_turn_a_path_into_another_host(): void
    {
        $this->rule(['type' => 'regex', 'source' => '^/go(.*)$', 'target' => '$1']);
        $this->rule(['type' => 'regex', 'source' => '^/jump/(.*)$', 'target' => '/$1', 'position' => 1]);

        foreach (['/go//evil.example', '/go/%2F%2Fevil.example', '/jump//evil.example', '/jump/%2Fevil.example'] as $url) {
            $response = $this->get($url);
            $this->assertNotContains($response->getStatusCode(), [301, 302], $url.' → '.$this->location($response));
        }

        // Rules see the decoded address; whatever a capture holds stays on this host.
        $this->assertSame(self::BASE.'/a/b', $this->location($this->get('/jump/a%2Fb')));
        $this->assertSame(self::BASE.'/%5Cevil.example', $this->location($this->get('/jump/%5Cevil.example')));
    }

    #[Test]
    public function external_targets_need_the_admins_say_so_and_keep_their_host(): void
    {
        $this->rule(['source' => '/shop', 'target' => 'https://shop.example/']);
        $this->assertNotSame(301, $this->get('/shop')->getStatusCode());

        $this->rule(['source' => '/store', 'target' => 'https://shop.example/', 'external' => true]);
        $this->assertSame('https://shop.example/', $this->location($this->get('/store')));

        $this->rule(['type' => 'regex', 'source' => '^/out/(.*)$', 'target' => 'https://shop.example/$1', 'external' => true]);
        $this->assertSame('https://shop.example/a%40b', $this->location($this->get('/out/a@b')));
    }

    #[Test]
    public function gone_answers_410_with_the_page(): void
    {
        $this->rule(['source' => '/retired', 'status' => 410]);

        $response = $this->get('/retired');
        $this->assertSame(410, $response->getStatusCode());
        $this->assertMatchesRegularExpression('~This page was removed|views\.gone_title~', (string) $response->getBody());

        $this->assertSame(410, $this->get('/retired', ['Accept' => 'application/json'])->getStatusCode());
    }

    #[Test]
    public function keep_query_passes_the_query_on(): void
    {
        $this->rule(['source' => '/search.php', 'target' => '/', 'keep_query' => true]);

        $this->assertSame(self::BASE.'/?q=roses&x=1', $this->location($this->get('/search.php?q=roses&x=1')));
    }

    #[Test]
    public function missing_rules_never_take_a_page_flarum_has(): void
    {
        $this->rule(['source' => '/d/1', 'target' => '/t/roses', 'mode' => 'missing']);
        $this->rule(['source' => '/d/999', 'target' => '/t/roses', 'mode' => 'missing']);

        $this->assertSame(200, $this->get('/d/1')->getStatusCode());
        $this->assertSame(self::BASE.'/t/roses', $this->location($this->get('/d/999')));
        // A private discussion is a 404 to a guest, so a "missing" rule may answer it.
        $this->assertSame(404, $this->get('/d/2')->getStatusCode());
    }

    #[Test]
    public function chains_are_followed_to_the_end_in_one_redirect(): void
    {
        $a = $this->rule(['source' => '/a', 'target' => '/b']);
        $b = $this->rule(['source' => '/b', 'target' => '/c', 'status' => 302]);

        $response = $this->get('/a');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(self::BASE.'/c', $this->location($response));

        $this->assertSame(1, $a->fresh()->hits);
        $this->assertSame(0, $b->fresh()->hits);
    }

    #[Test]
    public function a_loop_is_never_served(): void
    {
        $this->rule(['source' => '/loop-a', 'target' => '/loop-b']);
        $this->rule(['source' => '/loop-b', 'target' => '/loop-a']);
        $this->rule(['source' => '/self', 'target' => '/SELF/']);

        $this->assertSame(404, $this->get('/loop-a')->getStatusCode());
        $this->assertSame(404, $this->get('/self')->getStatusCode());
    }

    #[Test]
    public function disabled_rules_and_non_get_requests_are_left_alone(): void
    {
        $this->rule(['source' => '/off', 'target' => '/d/1', 'enabled' => false]);
        $this->rule(['source' => '/on', 'target' => '/d/1']);

        $this->assertSame(404, $this->get('/off')->getStatusCode());
        $this->assertNotSame(301, $this->send($this->request('POST', '/on'))->getStatusCode());
    }

    #[Test]
    public function counts_hits(): void
    {
        $rule = $this->rule(['source' => '/count', 'target' => '/d/1']);

        $this->get('/count');
        $this->get('/count');

        $this->assertSame(2, $rule->fresh()->hits);
        $this->assertNotNull($rule->fresh()->last_hit_at);
    }

    #[Test]
    public function a_saved_change_takes_effect_on_the_next_request(): void
    {
        $rule = $this->rule(['source' => '/moving', 'target' => '/d/1']);
        $this->assertSame(self::BASE.'/d/1', $this->location($this->get('/moving')));

        $rule->target = '/t/roses';
        $rule->save();

        $this->assertSame(self::BASE.'/t/roses', $this->location($this->get('/moving')));

        $rule->delete();
        $this->assertSame(404, $this->get('/moving')->getStatusCode());
    }

    #[Test]
    public function the_server_check_probe_answers(): void
    {
        $response = $this->get('/detour-probe.html');
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('ok', $response->getHeaderLine('X-Detour-Probe'));
    }
}
