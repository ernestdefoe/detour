<?php

namespace Ernestdefoe\Detour\Tests\integration;

use Ernestdefoe\Detour\Log\Missing;
use Ernestdefoe\Detour\Redirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class AdminApiTest extends DetourTestCase
{
    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '/redirects'],
            'create' => ['POST', '/redirects'],
            'update' => ['PATCH', '/redirects/1'],
            'delete' => ['DELETE', '/redirects/1'],
            'order' => ['POST', '/redirects/order'],
            'test' => ['POST', '/test'],
            'log' => ['GET', '/missing'],
            'ignore' => ['PATCH', '/missing/1'],
            'forget' => ['DELETE', '/missing/1'],
            'clear' => ['POST', '/missing/clear'],
            'presets' => ['GET', '/presets'],
            'apply' => ['POST', '/presets'],
            'export' => ['GET', '/export'],
            'import' => ['POST', '/import'],
        ];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function members_and_guests_are_refused(string $method, string $path): void
    {
        $this->rule(['source' => '/x', 'target' => '/d/1']);
        $this->database()->table('detour_missing')->insert(['url_hash' => sha1('/y'), 'url' => '/y', 'hits' => 1, 'first_seen_at' => \Carbon\Carbon::now(), 'last_seen_at' => \Carbon\Carbon::now(), 'ignored' => false]);

        $json = in_array($method, ['POST', 'PATCH'], true) ? ['source' => '/z', 'target' => '/d/1', 'platform' => 'phpbb', 'csv' => '/a,/b', 'url' => '/x', 'ids' => [1]] : null;

        $this->assertContains($this->api($method, $path, $json, 2)->getStatusCode(), [401, 403]);
        $guest = $this->send($this->request($method, '/api/detour'.$path, $json ? ['json' => $json] : []));
        $this->assertContains($guest->getStatusCode(), [400, 401, 403]);

        $this->assertSame(1, Redirect::query()->count());
        $this->assertSame(1, Missing::query()->count());
        $this->assertSame(0, Redirect::query()->sum('hits'));
    }

    #[Test]
    public function creates_and_lists_and_edits_and_deletes(): void
    {
        $created = $this->api('POST', '/redirects', ['type' => 'exact', 'source' => 'https://old.example/Forum/Page.html?id=3#top', 'target' => '/d/1', 'note' => 'from the old site']);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $data = $this->json($created)['data'];
        $this->assertSame('/Forum/Page.html?id=3', $data['source']);

        $list = $this->json($this->api('GET', '/redirects'));
        $this->assertSame(1, $list['total']);

        $edited = $this->api('PATCH', '/redirects/'.$data['id'], ['enabled' => false]);
        $this->assertSame(200, $edited->getStatusCode(), (string) $edited->getBody());
        $this->assertFalse($this->json($edited)['data']['enabled']);
        $this->assertSame('/d/1', $this->json($edited)['data']['target']);

        $this->assertSame(204, $this->api('DELETE', '/redirects/'.$data['id'])->getStatusCode());
        $this->assertSame(0, Redirect::query()->count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'another host by slashes' => [['source' => '/a', 'target' => '//evil.example'], 'target'],
            'backslash host' => [['source' => '/a', 'target' => '/\\evil.example'], 'target'],
            'scheme' => [['source' => '/a', 'target' => 'javascript:alert(1)'], 'target'],
            'external not allowed' => [['source' => '/a', 'target' => 'https://evil.example/'], 'target'],
            'capture in host' => [['type' => 'regex', 'source' => '^/a/(.*)$', 'target' => 'https://$1/', 'external' => true], 'target'],
            'capture as the host' => [['type' => 'regex', 'source' => '^/a(.*)$', 'target' => '/$1'], 'target'],
            'captures in exact' => [['source' => '/a', 'target' => '/d/$1'], 'target'],
            'home page' => [['source' => '/', 'target' => '/d/1'], 'source'],
            'regex catching home' => [['type' => 'regex', 'source' => '.*', 'target' => '/d/1'], 'source'],
            'bad regex' => [['type' => 'regex', 'source' => '^/a(', 'target' => '/d/1'], 'source'],
            'api' => [['source' => '/api/discussions', 'target' => '/d/1'], 'source'],
            'admin' => [['type' => 'prefix', 'source' => '/admin', 'target' => '/d/1'], 'source'],
            'not a path' => [['source' => 'old-page', 'target' => '/d/1'], 'source'],
            'self' => [['source' => '/a', 'target' => '/A/'], 'target'],
            'regex to itself' => [['type' => 'regex', 'source' => '^/b', 'target' => '/b/c'], 'target'],
            'status' => [['source' => '/a', 'target' => '/d/1', 'status' => 307], 'status'],
            'lookup on exact' => [['source' => '/a', 'lookup' => 'topic'], 'lookup'],
            'control characters' => [['source' => '/a', 'target' => "/d/1\r\nSet-Cookie: x=1"], 'target'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('refusals')]
    public function refuses_unsafe_or_broken_rules(array $body, string $field): void
    {
        $response = $this->api('POST', '/redirects', $body);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('/data/attributes/'.$field, $this->json($response)['errors'][0]['source']['pointer']);
        $this->assertSame(0, Redirect::query()->count());
    }

    #[Test]
    public function spaces_and_accents_in_a_target_are_encoded(): void
    {
        $response = $this->api('POST', '/redirects', ['source' => '/menu', 'target' => '/t/café crème']);

        $this->assertSame('/t/caf%C3%A9%20cr%C3%A8me', $this->json($response)['data']['target']);
        $this->assertSame(self::BASE.'/t/caf%C3%A9%20cr%C3%A8me', $this->location($this->get('/menu')));
    }

    #[Test]
    public function refuses_a_loop_through_other_rules_and_duplicates(): void
    {
        $this->rule(['source' => '/b', 'target' => '/c']);
        $this->rule(['source' => '/c', 'target' => '/a']);

        $this->assertSame(422, $this->api('POST', '/redirects', ['source' => '/a', 'target' => '/b'])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/redirects', ['source' => '/B/', 'target' => '/d/1'])->getStatusCode());
        // The same address can be answered in the other phase.
        $this->assertSame(201, $this->api('POST', '/redirects', ['source' => '/b', 'target' => '/d/1', 'mode' => 'missing'])->getStatusCode());
    }

    #[Test]
    public function test_endpoint_explains_what_would_happen(): void
    {
        $this->rule(['source' => '/a', 'target' => '/b']);
        $b = $this->rule(['source' => '/b', 'target' => '/d/1']);

        $out = $this->json($this->api('POST', '/test', ['url' => 'https://old.example/a']));

        $this->assertSame(self::BASE.'/d/1', $out['always']['target']);
        $this->assertSame([$b->id], $out['always']['chain']);
        $this->assertTrue($this->json($this->api('POST', '/test', ['url' => '/api/x']))['reserved']);
    }

    #[Test]
    public function the_log_lists_marks_covered_ignores_and_clears(): void
    {
        $this->get('/lost-one');
        $this->get('/lost-one');
        $this->get('/lost-two');
        $this->rule(['source' => '/lost-two', 'target' => '/d/1', 'mode' => 'missing']);

        $list = $this->json($this->api('GET', '/missing'));
        $this->assertSame(['/lost-one', '/lost-two'], array_column($list['data'], 'url'));
        $this->assertNull($list['data'][0]['ruleId']);
        $this->assertNotNull($list['data'][1]['ruleId']);

        $this->api('PATCH', '/missing/'.$list['data'][0]['id'], ['ignored' => true]);
        $this->assertSame(1, $this->json($this->api('GET', '/missing'))['total']);
        $this->assertSame(1, $this->json($this->api('GET', '/missing', null, 1, ['ignored' => 1]))['total']);

        $this->api('POST', '/missing/clear', []);
        $this->assertSame(1, Missing::query()->count(), 'ignored rows survive a plain clear');
    }

    #[Test]
    public function list_queries_do_not_grow_with_rows(): void
    {
        $this->app();
        for ($i = 0; $i < 30; $i++) {
            $this->rule(['source' => "/r$i", 'target' => '/d/1']);
        }
        for ($i = 0; $i < 30; $i++) {
            $this->get("/m$i");
        }

        $db = $this->database();
        foreach (['/redirects', '/missing'] as $path) {
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->api('GET', $path);
            $many = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'detour_'));

            $db->flushQueryLog();
            $this->api('GET', $path, null, 1, ['q' => '/r1', 'type' => 'exact']);
            $few = array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => str_contains($q, 'detour_'));
            $db->disableQueryLog();

            $this->assertSame(count($few), count($many), $path."\n".implode("\n", $many)."\n---\n".implode("\n", $few));
        }
    }
}
