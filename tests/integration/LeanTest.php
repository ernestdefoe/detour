<?php

namespace Ernestdefoe\Detour\Tests\integration;

use PHPUnit\Framework\Attributes\Test;

class LeanTest extends DetourTestCase
{
    /** @return list<string> */
    private function queriesFor(string $url): array
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $this->get($url);
        $queries = array_column($db->getQueryLog(), 'query');
        $db->disableQueryLog();

        return $queries;
    }

    #[Test]
    public function a_normal_page_runs_no_query_of_detours(): void
    {
        $this->rule(['source' => '/a', 'target' => '/d/1']);
        $this->rule(['type' => 'prefix', 'source' => '/old', 'target' => '/t/roses']);
        $this->rule(['type' => 'regex', 'source' => '^/x/(\d+)$', 'target' => '/d/$1']);
        $this->api('POST', '/presets', ['platform' => 'phpbb']);

        // The first request after a change compiles the rules once.
        $this->get('/');

        foreach (['/', '/d/1', '/t/roses', '/u/normal'] as $url) {
            $detour = array_filter($this->queriesFor($url), fn ($q) => str_contains($q, 'detour_') || str_contains($q, 'importer_'));
            $this->assertSame([], array_values($detour), $url);
        }
    }

    #[Test]
    public function a_redirect_costs_one_write(): void
    {
        $this->rule(['source' => '/a', 'target' => '/d/1']);
        $this->get('/');

        $queries = $this->queriesFor('/a');

        $this->assertCount(1, $queries, implode("\n", $queries));
        $this->assertStringContainsString('update', strtolower($queries[0]));
    }
}
