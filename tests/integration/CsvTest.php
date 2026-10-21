<?php

namespace Ernestdefoe\Detour\Tests\integration;

use Ernestdefoe\Detour\Redirect;
use PHPUnit\Framework\Attributes\Test;

class CsvTest extends DetourTestCase
{
    #[Test]
    public function imports_simple_rows_reporting_bad_lines(): void
    {
        $csv = "/old-page,/d/1\n/viewtopic.php?t=7,/d/7,302\n/retired,,410\n/evil,//evil.example\n/old-page,/d/2\n\n";

        $check = $this->json($this->api('POST', '/import', ['csv' => $csv, 'dryRun' => true]));
        $this->assertSame(3, $check['created']);
        $this->assertSame(2, $check['failed']);
        $this->assertSame([4, 5], array_column($check['errors'], 'line'));
        $this->assertSame(0, Redirect::query()->count());

        $done = $this->json($this->api('POST', '/import', ['csv' => $csv]));
        $this->assertSame(3, $done['created']);
        $this->assertSame(self::BASE.'/d/7', $this->location($this->get('/viewtopic.php?t=7')));
        $this->assertSame(410, $this->get('/retired')->getStatusCode());
    }

    #[Test]
    public function export_then_import_round_trips_and_defuses_formulas(): void
    {
        $this->rule(['source' => '/a', 'target' => '/d/1', 'note' => '=HYPERLINK("http://evil.example")', 'mode' => 'missing', 'keep_query' => true]);
        $this->rule(['type' => 'regex', 'source' => '^/b/(\d+)$', 'target' => '/d/$1', 'status' => 302]);

        $csv = (string) $this->api('GET', '/export')->getBody();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringStartsWith('type,source,target', $csv);

        Redirect::query()->delete();

        $result = $this->json($this->api('POST', '/import', ['csv' => $csv]));
        $this->assertSame(2, $result['created'], json_encode($result));

        $a = Redirect::query()->where('source', '/a')->first();
        $this->assertSame('=HYPERLINK("http://evil.example")', $a->note);
        $this->assertSame('missing', $a->mode);
        $this->assertTrue($a->keep_query);
        $this->assertSame(302, Redirect::query()->where('type', 'regex')->value('status'));
    }

    #[Test]
    public function rows_see_rows_imported_before_them(): void
    {
        $result = $this->json($this->api('POST', '/import', ['csv' => "/x,/y\n/y,/x\n"]));

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, $result['errors'][0]['line']);
    }

    #[Test]
    public function the_log_exports(): void
    {
        $this->get('/lost', ['Referer' => 'https://blog.example/a']);

        $csv = (string) $this->api('GET', '/export', null, 1, ['log' => 1])->getBody();

        $this->assertStringContainsString('/lost,1,', $csv);
        $this->assertStringContainsString('https://blog.example/a', $csv);
    }
}
